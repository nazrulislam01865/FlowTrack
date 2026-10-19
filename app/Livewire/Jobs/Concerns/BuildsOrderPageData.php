<?php

namespace App\Livewire\Jobs\Concerns;

use App\Queries\Orders\OrderListQuery;
use App\Queries\Orders\VisibleOrderQuery;
use App\Models\ClientDeliveryContact;
use App\Models\ClientShippingAddress;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\ClientService;
use App\Services\DocumentService;
use App\Services\MasterDataService;
use App\Services\OrderFinanceService;
use App\Services\OrderDetailViewService;
use App\Services\OrderRedoService;
use App\Services\Orders\OrderWorkflowSummaryService;
use App\Services\OrderWorkflowSetupService;
use App\Services\TaskService;
use App\Services\WorkspaceSettingsService;
use App\Support\BoardLaneResolver;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait BuildsOrderPageData
{
    private function createPageData(User $user): array
    {
        $master = app(MasterDataService::class);
        $options = app(\App\Services\FilterOptionService::class);

        // Create Job is a separate render branch. Keep only the selected
        // records needed to render dependent fields; large option lists are
        // loaded by the shared remote selector only when the user opens them.
        $clients = $this->clientId
            ? app(ClientService::class)
                ->referenceQuery($user, 'create-job')
                ->where('is_active', true)
                ->whereKey($this->clientId)
                ->with(['contacts' => fn ($query) => $query
                    ->select(['id', 'client_id', 'name', 'job_title', 'phone', 'is_primary', 'sort_order'])
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->orderBy('id')])
                ->get(['id', 'name', 'contact_name', 'contact_job_title', 'phone'])
            : collect();

        $savedShippingAddresses = $clients->isNotEmpty()
            ? ClientShippingAddress::query()
                ->where('client_id', $this->clientId)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'client_id', 'label', 'recipient', 'address_line1', 'suite', 'city', 'state', 'zip', 'country', 'is_default', 'sort_order'])
            : collect();

        $savedDeliveryContacts = $clients->isNotEmpty()
            ? ClientDeliveryContact::query()
                ->where('client_id', $this->clientId)
                ->whereIn('contact_type', ['end_customer', 'other_contact'])
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->get(['id', 'client_id', 'contact_type', 'name', 'phone_country_code', 'phone', 'last_used_at'])
            : collect();

        $phoneCountryCodeOptions = $options->options(
            $user,
            'phone-country-codes',
            'create-job',
            '',
            $this->shippingPhoneCountryCode,
            5,
        );

        // Shipping setup is visible before the lazy assignment section, so its
        // compact per-shipment selectors are intentionally sourced here. The
        // LocationMasterDataService keeps Country -> State parent rules shared
        // with Client and Order Detail address editors.
        $createShipmentLocationMaster = app(\App\Services\LocationMasterDataService::class);
        $createShipmentCountries = $createShipmentLocationMaster->countries()
            ->map(fn (MasterRecord $country) => [
                'id' => (string) $country->name,
                'label' => (string) $country->name,
                'meta' => trim((string) $country->code),
            ])
            ->values();
        $createShipmentStatesByCountry = collect($this->createShipments)
            ->pluck('country')
            ->map(fn ($country) => trim((string) $country))
            ->filter()
            ->unique(fn (string $country) => mb_strtolower($country))
            ->mapWithKeys(fn (string $country) => [
                $country => $createShipmentLocationMaster->statesForCountry($country)
                    ->map(fn (MasterRecord $state) => [
                        'id' => (string) $state->name,
                        'label' => (string) $state->name,
                        'meta' => trim((string) $state->code),
                    ])
                    ->values()
                    ->all(),
            ]);
        $createShipmentPhoneCodes = $master->active('phone_country_code')
            ->map(function (MasterRecord $record): array {
                $code = trim((string) $record->name);

                return [
                    'id' => $code,
                    'label' => $code,
                    'meta' => trim((string) $record->description),
                ];
            })
            ->filter(fn (array $option): bool => $option['id'] !== '')
            ->values();

        $access = app(AccessControlService::class);
        $canLinkInquiryOnCreate = $access->can($user, 'jobs', 'link')
            && $access->can($user, 'inquiries', 'view');
        // Keep Create Order light: each selected Inquiry's authorized display
        // row is cached in Livewire state when it is picked. Normal form rerenders
        // therefore do not query the Inquiry table again. Fresh eligibility is
        // still checked for the full set immediately before the Order is created.
        $selectedCreateInquiries = $canLinkInquiryOnCreate
            ? collect($this->createInquiryIds)
                ->map(function ($id) {
                    $id = (int) $id;
                    $selection = $this->createInquirySelections[$id]
                        ?? $this->createInquirySelections[(string) $id]
                        ?? null;
                    if (!is_array($selection) || blank($selection['label'] ?? null)) return null;

                    return [
                        'id' => $id,
                        'label' => (string) ($selection['label'] ?? ''),
                        'meta' => (string) ($selection['meta'] ?? ''),
                    ];
                })
                ->filter()
                ->values()
            : collect();
        $selectedCreateInquiry = $selectedCreateInquiries->first(); // backwards-compatible view data
        $createInquiryFilterOptions = collect();

        // Render Create Order from the shared Workflow Setup source of truth.
        // Only active, client-available and runtime-complete Order workflows are
        // selectable; Inquiry workflows remain separate.
        $workflows = $this->createWorkflowReady
            ? OrderWorkflowSetupService::orderWorkflowQuery()
                ->with([
                    'phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
                    'phases.taskPack.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                ])
                ->where('is_active', true)
                ->availableFor('orders', $this->clientId ? (int) $this->clientId : null)
                ->orderByRaw("CASE WHEN client_availability = 'specific' THEN 0 ELSE 1 END")
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->filter(fn (WorkflowTemplate $workflow): bool => app(OrderWorkflowSetupService::class)->isReadyForOrderCreation((int) $workflow->id))
                ->values()
            : collect();

        $workspaceId = $master->workspaceId();
        $canUseOrderProductSelector = $this->canUseCreateOrderProducts($user);
        $canViewProductCategories = $user->canModule('product_categories', 'view');
        $productCategories = collect();
        $productSearchResults = collect();
        $selectedProductDetails = collect();
        $selectedProductSuppliers = collect();
        $productSearchSuppliers = collect();
        $activeProductCount = 0;
        $productResultTotal = 0;

        if ($this->createCatalogReady && $canUseOrderProductSelector) {
            if ($canViewProductCategories) {
                $productCategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_category')
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']);
            }

            // Canonical Product catalogue source. This service can only return
            // master_records.type = 'product'. Product Categories are loaded
            // separately above and are permitted only as filters/metadata.
            $catalog = app(\App\Services\ProductCatalogService::class);
            $activeProductCount = $catalog->activeCount();

            $search = trim($this->createProductSearch);
            $categoryFilterId = $canViewProductCategories && ctype_digit(trim($this->createProductCategoryFilter))
                ? (int) $this->createProductCategoryFilter
                : 0;
            $productResultTotal = $catalog->orderSearchCount($search, $categoryFilterId ?: null);
            // With a small catalogue, show every Product record immediately so the
            // selector cannot be mistaken for a category suggestion list. Large
            // catalogues keep the prototype's Top matches + View all behaviour.
            $resultLimit = $this->createProductShowAllResults ? 100 : 3;
            $productSearchResults = $catalog->searchForOrderCreation($search, $categoryFilterId ?: null, $resultLimit);
            if ($this->clientId && $productSearchResults->isNotEmpty()) {
                $productSearchResults->load(['clientPrices' => fn ($query) => $query->where('client_id', (int) $this->clientId)]);
            }
            $productSearchSuppliers = $catalog->suppliersForProducts($productSearchResults->keyBy('id'));

            $selectedProductDetails = $catalog->selectedProducts(
                collect($this->jobItems)->pluck('product_id')
            );
            $selectedProductSuppliers = $catalog->suppliersForSelectionRows($this->jobItems);
        }

        $duplicateProduct = null;
        $newProductCategoryMatches = collect();
        $newProductSimilarCategories = collect();
        $newProductSimilarProducts = collect();
        $newProductSelectedCategory = null;
        $newProductHasExactCategory = false;
        $newProductImagePreview = null;
        $newProductSupplierOptions = collect();

        if ($canUseOrderProductSelector && $this->showCreateOrderProductModal) {
            // This optional picker is intentionally local inside the modal. Loading
            // the canonical active Supplier Master Data rows up front avoids a
            // second remote-dropdown lifecycle fighting Livewire modal morphs, and
            // guarantees that clicking the field always opens the real supplier list.
            $newProductSupplierOptions = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'metadata', 'type'])
                ->map(fn (MasterRecord $supplier) => [
                    'id' => (string) $supplier->id,
                    'label' => $supplier->supplierShortCode(),
                    'meta' => (string) $supplier->name,
                ])
                ->values();
            $code = trim($this->newProductCode);
            if ($code !== '') {
                $duplicateProduct = MasterRecord::withTrashed()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->with('parent:id,name,status')
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
                    ->first(['id', 'type', 'parent_id', 'name', 'code', 'metadata', 'status']);
            }

            $categorySearch = trim($this->newProductCategorySearch);
            if ($canViewProductCategories) {
                $newProductCategoryMatches = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_category')
                ->active()
                ->when($categorySearch !== '', fn ($query) => $query->whereLike('name', '%'.$categorySearch.'%'))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(6)
                ->get(['id', 'name', 'code']);

            if ($categorySearch !== '') {
                $newProductHasExactCategory = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_category')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($categorySearch)])
                    ->exists();

                $tokens = collect(preg_split('/\s+/', $categorySearch) ?: [])
                    ->map(fn ($token) => trim($token))
                    ->filter(fn ($token) => mb_strlen($token) >= 3)
                    ->take(3)
                    ->values();

                if ($tokens->isNotEmpty()) {
                    $newProductSimilarCategories = MasterRecord::query()
                        ->forWorkspace($workspaceId)
                        ->ofType('product_category')
                        ->active()
                        ->where(function ($query) use ($tokens) {
                            foreach ($tokens as $token) $query->orWhereLike('name', '%'.$token.'%');
                        })
                        ->when($newProductCategoryMatches->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $newProductCategoryMatches->pluck('id')))
                        ->orderBy('name')
                        ->limit(2)
                        ->get(['id', 'name', 'code']);
                }
            }

                if ($this->newProductCategoryId) {
                    $newProductSelectedCategory = MasterRecord::query()
                        ->forWorkspace($workspaceId)
                        ->ofType('product_category')
                        ->active()
                        ->find($this->newProductCategoryId, ['id', 'name', 'code']);
                }
            }

            $nameSearch = trim($this->newProductName);
            if (mb_strlen($nameSearch) >= 3) {
                $newProductSimilarProducts = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->active()
                    ->with('parent:id,name,status')
                    ->whereLike('name', '%'.$nameSearch.'%')
                    ->when($duplicateProduct, fn ($query) => $query->whereKeyNot($duplicateProduct->id))
                    ->orderBy('name')
                    ->limit(3)
                    ->get(['id', 'type', 'parent_id', 'name', 'code', 'metadata', 'status']);
            }

            if ($this->newProductImage) {
                try {
                    $newProductImagePreview = $this->newProductImage->temporaryUrl();
                } catch (Throwable) {
                    $newProductImagePreview = null;
                }
            }
        }

        return [
            'selectedJob' => null,
            'selectedTask' => null,
            'clients' => $clients,
            'savedShippingAddresses' => $savedShippingAddresses,
            'savedDeliveryContacts' => $savedDeliveryContacts,
            'phoneCountryCodeOptions' => $phoneCountryCodeOptions,
            'createShipmentCountries' => $createShipmentCountries,
            'createShipmentStatesByCountry' => $createShipmentStatesByCountry,
            'createShipmentPhoneCodes' => $createShipmentPhoneCodes,
            'createInquiryFilterOptions' => $createInquiryFilterOptions,
            'selectedCreateInquiry' => $selectedCreateInquiry,
            'selectedCreateInquiries' => $selectedCreateInquiries,
            'createInquirySelectorVersion' => $this->createInquirySelectorVersion,
            'canLinkInquiryOnCreate' => $canLinkInquiryOnCreate,
            'workflows' => $workflows,
            'categories' => collect(),
            'priorities' => $this->createAssignmentReady ? $master->active('priority') : collect(),
            'productionUrgencies' => $this->createAssignmentReady ? $master->active('production_urgency') : collect(),
            'shipmentMethods' => $master->active('shipment_method'),
            'shipmentUrgencies' => $master->active('shipment_urgency'),
            'categoryFilterOptions' => $this->createCatalogReady && $canUseOrderProductSelector && $canViewProductCategories
                ? $options->options($user, 'product-categories', 'create-job', '', null, 6)
                : collect(),
            'clientFilterOptions' => $options->options(
                $user,
                'clients',
                'create-job',
                '',
                $this->clientId,
                6,
            ),
            'ownerFilterOptions' => $this->createAssignmentReady
                ? $options->options($user, 'users', 'create-job', '', $this->ownerId, 6)
                : collect(),
            'workflowFilterOptions' => $this->createWorkflowReady
                ? $workflows->map(function (WorkflowTemplate $workflow): array {
                    $activePhases = $workflow->phases->where('is_active', true);
                    $taskCount = $activePhases->sum(fn (WorkflowPhase $phase) => $phase->taskPack?->items?->count() ?? 0);

                    return [
                        'id' => (int) $workflow->id,
                        'label' => (string) $workflow->name,
                        'meta' => $activePhases->count().' stages · '.$taskCount.' tasks · Workflow Setup',
                    ];
                })->values()
                : collect(),
            'productCategories' => $productCategories,
            'productSearchResults' => $productSearchResults,
            'productSearchSuppliers' => $productSearchSuppliers,
            'selectedProductDetails' => $selectedProductDetails,
            'selectedProductSuppliers' => $selectedProductSuppliers,
            'activeProductCount' => $activeProductCount,
            'productResultTotal' => $productResultTotal,
            'canUseOrderProductSelector' => $canUseOrderProductSelector,
            'canCreateCatalogProduct' => $canUseOrderProductSelector && $user->canModule('catalog_products', 'create'),
            'canViewProductCategories' => $canViewProductCategories,
            'canCreateProductCategory' => $canViewProductCategories && $user->canModule('product_categories', 'create'),
            'duplicateProduct' => $duplicateProduct,
            'newProductCategoryMatches' => $newProductCategoryMatches,
            'newProductSimilarCategories' => $newProductSimilarCategories,
            'newProductSimilarProducts' => $newProductSimilarProducts,
            'newProductSelectedCategory' => $newProductSelectedCategory,
            'newProductHasExactCategory' => $newProductHasExactCategory,
            'newProductImagePreview' => $newProductImagePreview,
            'newProductSupplierOptions' => $newProductSupplierOptions,
            'mentionUsers' => app(\App\Services\MentionService::class)->optionsForCreate($user),
        ];
    }

    private function taskPageData(User $user): array
    {
        $master = app(MasterDataService::class);

        // If an interaction forces a lazy section to hydrate, promote that
        // readiness into component state. Otherwise closing a modal / finishing
        // an upload can make the section disappear back into a skeleton until a
        // second IntersectionObserver request fires.
        if ($this->showTaskDocumentPicker || count($this->taskDocumentUploads) > 0) {
            $this->taskDetailSectionsReady['attachments'] = true;
        }
        if (filled($this->focusComment) || $this->taskActivityTab !== 'all' || $this->taskActivityPage > 1) {
            $this->taskDetailSectionsReady['activity'] = true;
        }

        $taskDetailSectionsReady = $this->taskDetailSectionsReady;

        $with = [
            'job.client:id,name,logo_path',
            'job.orderFlag:id,type,name,color,status,sort_order,metadata',
            'job.activeHold.holder:id,name,profile_image_path',
            'job.latestProductionMonitorActivity:activities.id,activities.subject_type,activities.subject_id,activities.user_id,activities.event,activities.description,activities.meta,activities.created_at',
            'assignee',
            'phase',
            'orderTaskStatus:id,type,name,color,status,sort_order,metadata',
            'orderTaskFlag:id,type,name,color,status,sort_order,metadata',
            'documentCategory',
            'setupTemplate.documentCategory',
        ];

        if ($taskDetailSectionsReady['checklist']) {
            $with[] = 'checklistItems';
        }
        if ($taskDetailSectionsReady['attachments']) {
            $with[] = 'documents.uploader:id,name';
            $with[] = 'links.creator:id,name';
        }
        if ($taskDetailSectionsReady['activity']) {
            $with[] = 'comments.user';
            $with[] = 'activities.user';
        }

        $task = app(TaskService::class)->visibleQuery($user)
            ->with($with)
            ->findOrFail($this->selectedTaskId);

        $taskActiveHold = $task->job?->activeHold;
        $taskOrderHoldContext = null;
        if ($taskActiveHold) {
            $sourceName = trim((string) ($taskActiveHold->source_name
                ?: ((string) $taskActiveHold->hold_from === \App\Models\OrderHold::FROM_CLIENT
                    ? ($task->job?->client?->name ?: 'Client')
                    : ($taskActiveHold->holder?->name ?: $taskActiveHold->holdFromLabel()))));

            $taskOrderHoldContext = [
                'isOnHold' => true,
                'hold' => [
                    'id' => (int) $taskActiveHold->id,
                    'holdFrom' => (string) $taskActiveHold->hold_from,
                    'holdFromLabel' => $taskActiveHold->holdFromLabel(),
                    'sourceName' => $sourceName ?: '—',
                    'reason' => (string) $taskActiveHold->reason,
                    'heldById' => $taskActiveHold->held_by ? (int) $taskActiveHold->held_by : null,
                    'heldBy' => (string) ($taskActiveHold->holder?->name ?: 'Unknown user'),
                    'startedAt' => $taskActiveHold->started_at,
                ],
            ];
        }

        $availableDocuments = $taskDetailSectionsReady['attachments'] && $this->showTaskDocumentPicker
            ? app(DocumentService::class)
                ->query($user, ['client' => $task->job?->client_id])
                ->with(['job:id,job_number', 'task:id,title'])
                ->latest('id')
                ->limit(60)
                ->get()
            : collect();

        return [
            'selectedJob' => null,
            'selectedTask' => $task,
            'taskStatuses' => $this->taskStatusOptions($master),
            'priorities' => $master->active('priority'),
            'taskFlags' => $master->active('order_task_flag'),
            'displayTimezone' => app(WorkspaceSettingsService::class)->displayTimezone(),
            'availableDocuments' => $availableDocuments,
            'mentionUsers' => app(\App\Services\MentionService::class)->optionsForTask($task, $user),
            'taskDetailSectionsReady' => $taskDetailSectionsReady,
            'taskOrderHoldContext' => $taskOrderHoldContext ?? ['isOnHold' => false, 'hold' => null],
        ];
    }

    private function jobPageData(User $user): array
    {
        if (! in_array($this->detailTab, ['overview', 'inquiry', 'finance', 'redo'], true)) {
            $this->detailTab = 'overview';
        }

        $master = app(MasterDataService::class);
        $orderQuery = app(VisibleOrderQuery::class);
        $selected = $orderQuery->base($user, $this->selectedJobId);

        // The Redo surface exists only after a Redo/discount action has actually
        // been initiated. Interactive tab clicks are already guarded in
        // setDetailTab(); this also protects direct URLs such as ?tab=redo.
        $preloadedRedoContext = null;
        if ($this->detailTab === 'redo') {
            $preloadedRedoContext = app(OrderRedoService::class)->context($selected, $user);
            if (! (bool) ($preloadedRedoContext['hasRedo'] ?? false)) {
                $this->detailTab = 'overview';
            }
        }

        // Products, Workflow, Attachments and Activity are isolated Livewire
        // children. The parent Order Details request owns only the always-visible
        // shell and therefore never hydrates those heavy lower-section graphs.
        $orderDetailSectionsReady = $this->orderDetailSectionsReady;

        if ($this->detailTab === 'overview') {
            // The always-visible summary is now served by a tiny per-Order read
            // model. Do not hydrate workflow phases/current tasks on every list
            // -> detail click. The isolated Workflow child still owns the full
            // authoritative runtime graph and reconciliation before task actions.
            $currentWorkflowPhaseId = (int) ($selected->workflow_phase_id ?: 0);
            if ($currentWorkflowPhaseId > 0) {
                $this->lastOverviewWorkflowPhaseId = $currentWorkflowPhaseId;
            }
            $this->overviewPhaseId = $currentWorkflowPhaseId ?: $this->overviewPhaseId;
        } else {
            $orderQuery->loadTab($selected, $user, $this->detailTab);
        }

        // Redo is an explicitly selected tab, so its activity is intentional
        // work and may load immediately. Overview activity stays viewport-lazy.
        if ($this->detailTab === 'redo') {
            $orderQuery->loadOverviewActivity(
                $selected,
                $this->jobActivityTab,
                $this->jobActivityPage,
                10,
            );
        }

        $availableDocuments = collect();

        $overviewTaskDocumentModalTask = null;
        $overviewTaskAvailableDocuments = collect();
        $overviewTaskArtworkRevision = ['active' => false, 'documents' => collect(), 'retained_documents' => collect()];

        $inquiryResults = collect();
        $selectedLinkInquiry = null;
        $access = app(AccessControlService::class);
        $canViewInquiries = $access->can($user, 'inquiries', 'view');
        $linkedInquiryCanOpen = $this->detailTab === 'inquiry' && $canViewInquiries;
        $canViewLinkedInquiryDocuments = $this->detailTab === 'inquiry'
            && $canViewInquiries
            && $access->can($user, 'documents', 'view');
        $canExportLinkedInquiryDocuments = $canViewLinkedInquiryDocuments
            && $access->can($user, 'documents', 'export');
        $canManageInquiryLink = $this->detailTab === 'inquiry'
            && $canViewInquiries
            && $access->can($user, 'jobs', 'link')
            && $access->canEditVisibleJob($user, $selected);

        if ($this->detailTab === 'inquiry'
            && $canManageInquiryLink
            && mb_strlen(trim($this->inquirySearch)) >= 2) {
            // Keep the search available after the first link so users can attach
            // as many eligible Inquiries as this Order requires.
            $inquiryResults = $orderQuery->inquiryLinkResults($user, $selected, $this->inquirySearch, 8);
            if ($this->selectedLinkInquiryId) {
                $selectedLinkInquiry = $inquiryResults->firstWhere('id', $this->selectedLinkInquiryId);
                if (!$selectedLinkInquiry) $this->selectedLinkInquiryId = null;
            }
        }

        $financeSummary = null;
        $financeContacts = collect();
        $financeUsers = collect();
        $financeInvoiceTypes = collect();
        $financeCurrencies = collect();
        $financePaymentTerms = collect();
        $financePaymentMethods = collect();
        $financeReceivedAccounts = collect();
        $canCreateFinance = false;
        $canEditFinance = false;
        if ($this->detailTab === 'finance') {
            $financeSummary = app(OrderFinanceService::class)->summary($selected);
            $financeContacts = $selected->client?->contacts ?? collect();
            $financeInvoiceTypes = $master->active('invoice_type');
            $financeCurrencies = $master->active('currency');
            $financePaymentTerms = $master->active('payment_term');
            $financePaymentMethods = $master->active('payment_method');
            $financeReceivedAccounts = $master->active('received_account');
            $canCreateFinance = app(AccessControlService::class)->can($user, 'finance', 'create');
            $canEditFinance = app(AccessControlService::class)->canEditParentRecordModule($user, 'finance', $selected);
            if ($canEditFinance) {
                $financeUsers = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'profile_image_path']);
            }
        }

        $jobProductSearchResults = collect();
        $jobProductSearchSuppliers = collect();
        $jobProductResultTotal = 0;
        $jobProductSelectedProduct = null;
        $jobProductSelectedSupplier = null;
        if ($this->detailTab === 'overview' && $orderDetailSectionsReady['products'] && $this->showAddJobProductForm) {
            $catalog = app(\App\Services\ProductCatalogService::class);
            $productSearch = trim($this->jobProductSearch);
            $jobProductResultTotal = $catalog->orderSearchCount($productSearch, null);
            $resultLimit = $this->jobProductShowAllResults || $jobProductResultTotal <= 20 ? 20 : 3;
            $jobProductSearchResults = $catalog->searchForOrderCreation($productSearch, null, $resultLimit);
            $jobProductSearchSuppliers = $catalog->suppliersForProducts($jobProductSearchResults->keyBy('id'));
            if ($this->jobProductSelectedId) {
                $jobProductSelectedProduct = $catalog->selectedProducts([$this->jobProductSelectedId])->first();
                $jobProductSelectedSupplier = $jobProductSelectedProduct
                    ? $catalog->supplierForProduct($jobProductSelectedProduct)
                    : null;
            }
        }

        $editOrderProductSearchResults = collect();
        $editOrderProductSearchSuppliers = collect();
        $editOrderProductResultTotal = 0;
        $editOrderProductSelectedProduct = null;
        $editOrderProductSelectedSupplier = null;
        if (
            $this->detailTab === 'overview'
            && $orderDetailSectionsReady['products']
            && $this->showEditOrderProductModal
            && $this->editOrderProductItemId
        ) {
            $catalog = app(\App\Services\ProductCatalogService::class);
            $productSearch = trim($this->editOrderProductSearch);
            $editOrderProductResultTotal = $catalog->orderSearchCount($productSearch, null);
            $resultLimit = $this->editOrderProductShowAllResults || $editOrderProductResultTotal <= 20 ? 20 : 3;
            $editOrderProductSearchResults = $catalog->searchForOrderCreation($productSearch, null, $resultLimit);
            $editOrderProductSearchSuppliers = $catalog->suppliersForProducts($editOrderProductSearchResults->keyBy('id'));
            if ($this->editOrderProductSelectedId) {
                $editOrderProductSelectedProduct = $catalog->selectedProducts([$this->editOrderProductSelectedId])->first();
            }
            if ($this->editOrderProductSupplierId) {
                $editOrderProductSelectedSupplier = \App\Models\MasterRecord::query()
                    ->forWorkspace(app(\App\Services\MasterDataService::class)->workspaceId())
                    ->ofType('supplier')
                    ->active()
                    ->find((int) $this->editOrderProductSupplierId, ['id', 'name', 'code', 'status']);
            }
        }

        $shipmentUrgencyOptions = $master->active('shipment_urgency');
        $workflowSummary = $this->detailTab === 'overview'
            ? app(OrderWorkflowSummaryService::class)->forViewer($selected, $user)
            : [];
        // The Planning & ownership selector now represents the complete shipping
        // choice (Sea/Air/Road or Express urgency), so it needs the active method
        // list in addition to urgency master data. MasterDataService keeps this
        // collection cached; this does not hydrate Workflow task relations.
        $shipmentMethodOptions = $master->active('shipment_method');
        $courierOptions = collect();

        $shipmentCountryOptions = collect();
        $shipmentStateOptions = collect();

        $orderDetailContext = app(OrderDetailViewService::class)->buildSummary($selected, $user, $shipmentUrgencyOptions, $shipmentMethodOptions);
        $orderDetailContext['workflowSummary'] = $workflowSummary;
        $orderDetailContext['workflowName'] = (string) ($workflowSummary['workflow_name'] ?? 'FlowTrack Order Workflow');
        $orderDetailContext['shipmentMethods'] = $shipmentMethodOptions;
        $orderDetailContext['shipmentUrgencies'] = $shipmentUrgencyOptions;
        $orderDetailContext['shipmentCouriers'] = $courierOptions;
        $orderDetailContext['shipmentCountries'] = $shipmentCountryOptions
            ->map(fn (MasterRecord $country) => [
                'id' => (string) $country->name,
                'label' => (string) $country->name,
                'meta' => trim((string) $country->code),
            ])
            ->values()
            ->all();
        $orderDetailContext['shipmentStates'] = $shipmentStateOptions
            ->map(fn (MasterRecord $state) => [
                'id' => (string) $state->name,
                'label' => (string) $state->name,
                'meta' => trim((string) $state->code),
            ])
            ->values()
            ->all();
        $orderDetailContext['shipmentPhoneCountryCodes'] = $this->showShipmentModal
            ? $master->active('phone_country_code')
                ->map(function (MasterRecord $record): array {
                    $code = trim((string) $record->name);

                    return [
                        'id' => $code,
                        'label' => $code,
                        'meta' => trim((string) $record->description),
                    ];
                })
                ->filter(fn (array $option): bool => $option['id'] !== '')
                ->values()
                ->all()
            : [];
        $orderDetailContext['shipmentSavedAddresses'] = $this->showShipmentModal && $selected->client_id
            ? ClientShippingAddress::query()
                ->where('client_id', (int) $selected->client_id)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ClientShippingAddress $address): array => [
                    'id' => (int) $address->id,
                    'label' => trim((string) ($address->label ?: 'Shipping address')),
                    'is_default' => (bool) $address->is_default,
                    'recipient' => trim((string) $address->recipient),
                    'address_line1' => trim((string) $address->address_line1),
                    'suite' => trim((string) $address->suite),
                    'city' => trim((string) $address->city),
                    'state' => trim((string) $address->state),
                    'zip' => trim((string) $address->zip),
                    'country' => trim((string) $address->country),
                ])
                ->values()
                ->all()
            : [];
        $orderDetailContext['workflowEmailResendFeedback'] = $this->orderWorkflowEmailResendFeedback;
        $orderRedoContext = $preloadedRedoContext
            ?? app(OrderRedoService::class)->summaryContext($selected, $user);
        $orderRedoForm = ($this->showRedoModal || $this->detailTab === 'redo')
            ? $this->redoFormState($selected)
            : [];

        return [
            'selectedJob' => $selected,
            'selectedTask' => null,
            'taskStatuses' => collect(),
            'users' => collect(),
            'priorities' => collect(),
            'shipmentUrgencyOptions' => $shipmentUrgencyOptions,
            'orderDetailContext' => $orderDetailContext,
            'orderRedoContext' => $orderRedoContext,
            'orderRedoForm' => $orderRedoForm,
            'overviewPhaseId' => $this->overviewPhaseId,
            'orderDetailSectionsReady' => $orderDetailSectionsReady,
            // Product/category options on Job Details are loaded remotely only
            // when an inline dropdown opens, avoiding full catalog payloads.
            'products' => collect(),
            'categories' => collect(),
            'availableDocuments' => $availableDocuments,
            'overviewTaskDocumentModalTask' => $overviewTaskDocumentModalTask,
            'overviewTaskAvailableDocuments' => $overviewTaskAvailableDocuments,
            'overviewTaskArtworkRevision' => $overviewTaskArtworkRevision,
            // The always-visible Overview description editor uses mentions.
            // Keep this lightweight directory available for design/functionality
            // parity; Activity and Workflow maintain their own isolated copies.
            'mentionUsers' => app(\App\Services\MentionService::class)->optionsForJob($selected, $user),
            'inquiryResults' => $inquiryResults,
            'selectedLinkInquiry' => $selectedLinkInquiry,
            'canManageInquiryLink' => $canManageInquiryLink,
            'linkedInquiryCanOpen' => $linkedInquiryCanOpen,
            'canViewLinkedInquiryDocuments' => $canViewLinkedInquiryDocuments,
            'canExportLinkedInquiryDocuments' => $canExportLinkedInquiryDocuments,
            'financeSummary' => $financeSummary,
            'financeContacts' => $financeContacts,
            'financeUsers' => $financeUsers,
            'financeInvoiceTypes' => $financeInvoiceTypes,
            'financeCurrencies' => $financeCurrencies,
            'financePaymentTerms' => $financePaymentTerms,
            'financePaymentMethods' => $financePaymentMethods,
            'financeReceivedAccounts' => $financeReceivedAccounts,
            'canCreateFinance' => $canCreateFinance,
            'canEditFinance' => $canEditFinance,
            'canViewFinance' => app(AccessControlService::class)->can($user, 'finance', 'view'),
            'jobProductSearchResults' => $jobProductSearchResults,
            'jobProductSearchSuppliers' => $jobProductSearchSuppliers,
            'jobProductResultTotal' => $jobProductResultTotal,
            'jobProductSelectedProduct' => $jobProductSelectedProduct,
            'jobProductSelectedSupplier' => $jobProductSelectedSupplier,
            'editOrderProductSearchResults' => $editOrderProductSearchResults,
            'editOrderProductSearchSuppliers' => $editOrderProductSearchSuppliers,
            'editOrderProductResultTotal' => $editOrderProductResultTotal,
            'editOrderProductSelectedProduct' => $editOrderProductSelectedProduct,
            'editOrderProductSelectedSupplier' => $editOrderProductSelectedSupplier,
        ];
    }

    private function jobsTableData(User $user): array
    {
        // The Orders list is intentionally its own lightweight render branch.
        // It does not hydrate filter catalogs, task collections, members or
        // inline-edit option lists that are not visible in the supplied
        // performance prototype.
        $jobs = app(OrderListQuery::class)->paginateLegacy(
            $user,
            $this->search,
            $this->perPage,
            $this->client !== '' ? (int) $this->client : null,
            $this->phase !== '' ? (int) $this->phase : null,
            $this->assignee !== '' ? (int) $this->assignee : null,
        );
        $options = app(\App\Services\FilterOptionService::class);

        return [
            'selectedJob' => null,
            'selectedTask' => null,
            'jobs' => $jobs,
            'clientFilterOptions' => $this->client !== ''
                ? $options->options($user, 'clients', 'jobs', '', (int) $this->client, 5)
                : collect(),
            'phaseFilterOptions' => $this->phase !== ''
                ? $options->options($user, 'phases', 'order-list', '', (int) $this->phase, 5)
                : collect(),
            'assigneeFilterOptions' => $this->assignee !== ''
                ? $options->options($user, 'users', 'order-list', '', (int) $this->assignee, 5)
                : collect(),
        ];
    }

    private function userOptions(User $user)
    {
        $isCreator = $this->selectedJobId
            ? FlowJob::query()->whereKey($this->selectedJobId)->where('created_by', $user->id)->exists()
            : false;
        $canAssign = $isCreator || $user->canModule('tasks', 'assign') || $user->canModule('jobs', 'assign');

        return $canAssign
            ? User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'profile_image_path'])
            : collect([(object) ['id' => $user->id, 'name' => $user->name, 'profile_image_path' => $user->profile_image_path]]);
    }

    private function taskStatusOptions(MasterDataService $master)
    {
        return collect(BoardLaneResolver::taskStatuses(
            $master->active('order_task_status')->pluck('name')
        ));
    }

    private function jobFilters(): array
    {
        return [
            'search' => $this->search,
            'phase' => $this->phase,
            'client' => $this->client,
            'owner' => $this->owner,
            'assignee' => $this->assignee,
            'delivery' => $this->delivery,
            'invoice' => $this->invoice,
            'priority' => $this->priorityFilter,
            'status' => $this->jobStatusFilter,
            'sort' => $this->sort,
        ];
    }

    private function resetOverviewTaskResourceUi(): void
    {
        $this->showOverviewTaskDocumentModal = false;
        $this->overviewTaskDocumentModalTaskId = null;
        $this->overviewTaskDocumentSource = 'upload';
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskExistingDocumentId = null;
        $this->overviewTaskDocumentNote = '';
        $this->overviewTaskLinkFormTaskId = null;
        $this->overviewTaskLinkUrl = '';
    }

    private function resetJobSelection(): void
    {
        $this->selectedJobIds = [];
        $this->resetPage();
    }

}
