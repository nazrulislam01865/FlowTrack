<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\MasterRecord;
use App\Models\Document;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Inquiries\InquiryRfqService;
use App\Services\MentionService;
use App\Services\MasterDataService;
use Throwable;

trait BuildsInquiryPageData
{
    private function listPageData(User $user): array
    {
        $listQuery = app(\App\Queries\Inquiries\InquiryListQuery::class);
        $selectedClientId = $this->listClient !== '' ? (int) $this->listClient : null;
        $pendingClientId = $this->pendingListClient !== '' ? (int) $this->pendingListClient : $selectedClientId;
        $filters = $this->currentInquiryListFilters();
        $paginator = $listQuery->paginate($user, $filters, self::INQUIRIES_PER_PAGE);
        $listClientFilterOptions = app(\App\Services\FilterOptionService::class)
            ->options($user, 'clients', 'inquiries', '', $pendingClientId, 6);
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        $rows = $listQuery->rows($paginator, $user)->map(function (array $row) use ($detailQuery): array {
            $row['statusColor'] = $detailQuery->statusColor(
                (string) ($row['status'] ?? ''),
                (string) ($row['taskStatus'] ?? ''),
            );
            return $row;
        });

        return [
            'mode' => 'list',
            'inquiryPaginator' => $paginator,
            'inquiryRows' => $rows,
            'listClientFilterOptions' => $listClientFilterOptions,
            'listStatusOptions' => $listQuery->taskStatusOptions(),
            'selectedInquiry' => null,
            'selectedTask' => null,
        ];
    }

    private function createPageData(): array
    {
        $user = auth()->user();
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $canUseInquiryProductSelector = $this->canUseCreateInquiryProducts($user);
        $catalogReady = $canUseInquiryProductSelector
            && ($this->createCatalogReady || $this->showCreateOrderProductModal);
        $canViewProductCategories = $user->canModule('product_categories', 'view');
        $productCategories = collect();
        $productSearchResults = collect();
        $selectedProductDetails = collect();
        $selectedProductSuppliers = collect();
        $productSearchSuppliers = collect();
        $activeProductCount = 0;
        $productResultTotal = 0;
        $rfqService = app(InquiryRfqService::class);
        $selectedRfqSupplierIds = collect($this->createProductRfqRows)
            ->flatMap(fn ($state) => is_array($state) ? ($state['supplier_ids'] ?? []) : [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $selectedRfqSupplierRecords = $rfqService
            ->selectableSuppliersByIds($workspaceId, $selectedRfqSupplierIds->all())
            ->keyBy(fn (MasterRecord $supplier) => (int) $supplier->id);
        $createProductRfqSelectedSuppliers = collect($this->createProductRows)
            ->map(function (array $row, int $index) use ($selectedRfqSupplierRecords) {
                $ids = collect(data_get($this->createProductRfqRows, $index.'.supplier_ids', []))
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique();

                return $ids->map(function (int $supplierId) use ($selectedRfqSupplierRecords): ?array {
                    $supplier = $selectedRfqSupplierRecords->get($supplierId);
                    if (! $supplier) return null;
                    $email = trim((string) data_get($supplier->metadata, 'email'));
                    return [
                        'id' => (int) $supplier->id,
                        'name' => (string) $supplier->name,
                        'code' => trim((string) $supplier->code),
                        'short_code' => $supplier->supplierShortCode(),
                        'email' => $email,
                        'contact' => trim((string) data_get($supplier->metadata, 'contact_person')),
                        'email_ready' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                    ];
                })->filter()->values();
            });
        $createRfqSelectedSuppliers = $selectedRfqSupplierRecords->values()->map(function (MasterRecord $supplier): array {
            return [
                'id' => (int) $supplier->id,
                'name' => (string) $supplier->name,
                'code' => trim((string) $supplier->code),
                'short_code' => $supplier->supplierShortCode(),
                'email' => trim((string) data_get($supplier->metadata, 'email')),
                'contact' => trim((string) data_get($supplier->metadata, 'contact_person')),
            ];
        });
        // Kept for backward-compatible view data; the prototype picker itself is
        // remote and does not hydrate the full Supplier directory on page load.
        $createRfqSupplierCandidates = collect();
        $createRfqProductCount = collect($this->createProductRows)
            ->filter(fn (array $row): bool => (int) ($row['product_id'] ?? 0) > 0 || trim((string) ($row['product'] ?? '')) !== '')
            ->count();

        if ($catalogReady) {
            if ($canViewProductCategories) {
                $productCategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_category')
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']);
            }

            $catalog = app(\App\Services\ProductCatalogService::class);
            $activeProductCount = $catalog->activeCount();
            $search = trim($this->createProductSearch);
            $categoryFilterId = $canViewProductCategories && ctype_digit(trim($this->createProductCategoryFilter))
                ? (int) $this->createProductCategoryFilter
                : 0;
            $productResultTotal = $catalog->orderSearchCount($search, $categoryFilterId ?: null);
            $resultLimit = $this->createProductShowAllResults ? 100 : 3;
            $productSearchResults = $catalog->searchForOrderCreation($search, $categoryFilterId ?: null, $resultLimit);
            $productSearchSuppliers = $catalog->suppliersForProducts($productSearchResults->keyBy('id'));
            $selectedProductDetails = $catalog->selectedProducts(collect($this->createProductRows)->pluck('product_id'));
            $selectedProductSuppliers = $catalog->suppliersForProducts($selectedProductDetails);
        }

        $duplicateProduct = null;
        $newProductCategoryMatches = collect();
        $newProductSimilarCategories = collect();
        $newProductSimilarProducts = collect();
        $newProductSelectedCategory = null;
        $newProductHasExactCategory = false;
        $newProductImagePreview = null;

        if ($canUseInquiryProductSelector && $this->showCreateOrderProductModal) {
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
            'mode' => 'create',
            'selectedInquiry' => null,
            'selectedTask' => null,
            'catalogReady' => $catalogReady,
            'canUseInquiryProductSelector' => $canUseInquiryProductSelector,
            'productCategories' => $productCategories,
            'productSearchResults' => $productSearchResults,
            'productSearchSuppliers' => $productSearchSuppliers,
            'selectedProductDetails' => $selectedProductDetails,
            'selectedProductSuppliers' => $selectedProductSuppliers,
            'activeProductCount' => $activeProductCount,
            'productResultTotal' => $productResultTotal,
            'canCreateCatalogProduct' => $canUseInquiryProductSelector && $user->canModule('catalog_products', 'create'),
            'canViewProductCategories' => $canViewProductCategories,
            'canCreateProductCategory' => $canViewProductCategories && $user->canModule('product_categories', 'create'),
            'duplicateProduct' => $duplicateProduct,
            'newProductCategoryMatches' => $newProductCategoryMatches,
            'newProductSimilarCategories' => $newProductSimilarCategories,
            'newProductSimilarProducts' => $newProductSimilarProducts,
            'newProductSelectedCategory' => $newProductSelectedCategory,
            'newProductHasExactCategory' => $newProductHasExactCategory,
            'newProductImagePreview' => $newProductImagePreview,
            'createPriorityOptions' => app(MasterDataService::class)->active('priority'),
            'createRfqSupplierCandidates' => $createRfqSupplierCandidates,
            'createRfqSelectedSuppliers' => $createRfqSelectedSuppliers,
            'createRfqProductCount' => $createRfqProductCount,
            'createProductRfqSelectedSuppliers' => $createProductRfqSelectedSuppliers,
        ];
    }

    private function detailPageData(User $user): array
    {
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        $access = app(AccessControlService::class);
        $canViewInquiryProducts = $access->can($user, 'catalog_products', 'view');
        $canViewTasks = $user->canModule('tasks', 'view')
            || Inquiry::query()->whereKey($this->selectedInquiryId)->where('created_by', $user->id)->exists();
        $detailSectionsReady = array_merge([
            'products' => false,
            'taskflow' => false,
            'documents' => false,
            'activity' => false,
        ], $this->inquiryDetailSectionsReady ?? []);
        if ($this->showAddInquiryProductForm || $this->editingInquiryProducts || $this->editInquiryProductItemId) {
            $detailSectionsReady['products'] = true;
        }
        if ($this->selectedTaskId || $this->showTaskDocumentModal || $this->showAddTaskForm || $this->showTaskAttentionModal || $this->taskLinkFormTaskId) {
            $detailSectionsReady['taskflow'] = true;
        }
        if ($this->showInquiryDocumentPicker || count($this->inquiryUploads ?? []) > 0) {
            $detailSectionsReady['documents'] = true;
        }
        if ($this->inquiryActivityTab !== 'all') {
            $detailSectionsReady['activity'] = true;
        }
        $rfqContext = in_array($this->detailTab, ['rfq', 'comparison'], true) || $this->showRfqSettings;
        $with = [
            'client:id,name,logo_path',
            'creator:id,name,profile_image_path',
            'owner:id,name,profile_image_path',
            'convertedJob:id,job_number,order_number',
            'sourceWorkflow:id,name',
            'currentTask:id,inquiry_id,assignee_id,title,due_date,status,needs_attention,attention_reason,started_at,completed_at',
            'currentTask.assignee:id,name,profile_image_path',
        ];
        if (($canViewInquiryProducts && $detailSectionsReady['products']) || $rfqContext) {
            $with[] = 'items:id,inquiry_id,category,item_name,quantity,unit,unit_price,notes,sort_order,created_at,updated_at';
        }
        if ($this->detailTab === 'overview' && $canViewTasks) {
            if ($detailSectionsReady['taskflow']) {
                // The interactive Taskflow is the expensive task graph: people,
                // submission evidence, links and counts load only near the section.
                $with['tasks'] = fn ($query) => app(AccessControlService::class)->applyInquiryTaskScope($query, $user)
                    ->with([
                        'assignee:id,name,profile_image_path',
                        'sourceTaskPackItem:id,color',
                        'documents:id,inquiry_id,inquiry_task_id,name,note,mime_type,created_at',
                        'links:id,inquiry_task_id,url,created_at',
                    ])
                    ->withCount(['documents', 'comments'])
                    ->orderBy('sequence');
            } else {
                // Keep only the small task state needed by the header/properties.
                // This avoids hydrating the full Taskflow while preserving dates,
                // attention state and the current-workflow summary above the fold.
                $with['tasks'] = fn ($query) => app(AccessControlService::class)->applyInquiryTaskScope($query, $user)
                    ->select(['id','inquiry_id','assignee_id','title','due_date','status','needs_attention','attention_reason','started_at','completed_at','sequence'])
                    ->orderBy('sequence');
            }
        }

        $inquiry = $detailQuery->detail($user, (int) $this->selectedInquiryId, $with, [
            'tasks',
            'tasks as completed_tasks_count' => fn ($q) => $q->whereNotNull('completed_at'),
            'documents',
        ]);

        if (!$canViewTasks) {
            $inquiry->setRelation('tasks', collect());
            $inquiry->setRelation('currentTask', null);
            $inquiry->setAttribute('tasks_count', 0);
            $inquiry->setAttribute('completed_tasks_count', 0);
        }

        $inquiryProductMasters = collect();
        $inquiryProductSuppliers = collect();
        $inquiryProductAllSuppliers = collect();
        $inquiryProductRfqOverview = [
            'stats' => ['products' => 0, 'supplier_assignments' => 0, 'invitations_sent' => 0, 'quotations_received' => 0],
            'rows' => collect(),
            'product_count' => 0,
            'total_units' => 0.0,
        ];
        if ((($canViewInquiryProducts && $detailSectionsReady['products']) || $rfqContext) && $inquiry->relationLoaded('items')) {
            $productNames = $inquiry->items
                ->pluck('item_name')
                ->filter(fn ($name) => filled($name))
                ->unique()
                ->values();

            if ($productNames->isNotEmpty()) {
                $inquiryProductMasters = \App\Models\MasterRecord::query()
                    ->where('workspace_id', max(1, (int) config('flowtrack.workspace_id', 1)))
                    ->where('type', 'product')
                    ->whereIn('name', $productNames)
                    ->with('parent')
                    ->get()
                    ->keyBy(fn ($record) => mb_strtolower(trim((string) $record->name)));

                // Resolve Product Master suppliers in one bounded query so the
                // Inquiry and Order detail cards can share the same Supplier column
                // without introducing any relationship queries inside Blade.
                $catalog = app(\App\Services\ProductCatalogService::class);
                $productsById = $inquiryProductMasters->values()->keyBy('id');
                $inquiryProductSuppliers = $catalog->suppliersForProducts($productsById);
                $inquiryProductAllSuppliers = $catalog->allSuppliersForProducts($productsById);
            }

            if ($this->detailTab === 'overview') {
                $overviewInvitations = app(\App\Services\Inquiries\InquiryRfqService::class)
                    ->overviewInvitations($inquiry);
                $inquiryProductRfqOverview = \App\Support\InquiryProductRfqOverviewPresenter::build(
                    $inquiry,
                    $inquiryProductMasters,
                    $inquiryProductAllSuppliers,
                    $overviewInvitations,
                );
            }
        }

        $inquiryCurrency = strtoupper((string) ($inquiry->currency ?: 'USD'));
        $inquiryCurrencySymbol = match ($inquiryCurrency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CNY', 'RMB' => '¥',
            default => $inquiryCurrency.' ',
        };

        $inquiryProductSearchResults = collect();
        $inquiryProductSearchSuppliers = collect();
        $inquiryProductResultTotal = 0;
        $inquiryProductSelectedProduct = null;
        $inquiryProductSelectedSupplier = null;
        if ($this->detailTab === 'overview' && $detailSectionsReady['products'] && $this->showAddInquiryProductForm && $canViewInquiryProducts) {
            $catalog = app(\App\Services\ProductCatalogService::class);
            $productSearch = trim($this->inquiryProductSearch);
            $inquiryProductResultTotal = $catalog->orderSearchCount($productSearch, null);
            $resultLimit = $this->inquiryProductShowAllResults || $inquiryProductResultTotal <= 20 ? 20 : 3;
            $inquiryProductSearchResults = $catalog->searchForOrderCreation($productSearch, null, $resultLimit);
            $inquiryProductSearchSuppliers = $catalog->suppliersForProducts($inquiryProductSearchResults->keyBy('id'));
            if ($this->inquiryProductSelectedId) {
                $inquiryProductSelectedProduct = $catalog->selectedProducts([$this->inquiryProductSelectedId])->first();
                $inquiryProductSelectedSupplier = $inquiryProductSelectedProduct
                    ? $catalog->supplierForProduct($inquiryProductSelectedProduct)
                    : null;
            }
        }

        $editInquiryProductSearchResults = collect();
        $editInquiryProductSearchSuppliers = collect();
        $editInquiryProductResultTotal = 0;
        $editInquiryProductSelectedProduct = null;
        $editInquiryProductSelectedSupplier = null;
        if (
            $this->detailTab === 'overview'
            && $detailSectionsReady['products']
            && $this->editInquiryProductItemId
            && $canViewInquiryProducts
        ) {
            $catalog = app(\App\Services\ProductCatalogService::class);
            $productSearch = trim($this->editInquiryProductSearch);
            $editInquiryProductResultTotal = $catalog->orderSearchCount($productSearch, null);
            $resultLimit = $this->editInquiryProductShowAllResults || $editInquiryProductResultTotal <= 20 ? 20 : 3;
            $editInquiryProductSearchResults = $catalog->searchForOrderCreation($productSearch, null, $resultLimit);
            $editInquiryProductSearchSuppliers = $catalog->suppliersForProducts($editInquiryProductSearchResults->keyBy('id'));
            if ($this->editInquiryProductSelectedId) {
                $editInquiryProductSelectedProduct = $catalog->selectedProducts([$this->editInquiryProductSelectedId])->first();
                $editInquiryProductSelectedSupplier = $editInquiryProductSelectedProduct
                    ? $catalog->supplierForProduct($editInquiryProductSelectedProduct)
                    : null;
            }
        }

        // Documents and Activity remain part of Overview, but no longer have separate tabs.
        $documents = $this->detailTab === 'overview' && $detailSectionsReady['documents'] && $user->canModule('documents', 'view') ? $detailQuery->documents($user, $inquiry) : null;
        $activities = $this->detailTab === 'overview' && $detailSectionsReady['activity'] ? $detailQuery->activity($user, $inquiry, 30, $this->inquiryActivityTab) : null;
        $needsMentionUsers = $detailSectionsReady['activity'] || $this->showInquiryAttentionModal || $this->showTaskAttentionModal;
        $mentionUsers = $this->detailTab === 'overview' && $needsMentionUsers ? app(MentionService::class)->optionsForCreate($user) : collect();
        $availableInquiryDocuments = $detailSectionsReady['documents'] && $this->showInquiryDocumentPicker && $this->detailTab === 'overview'
            ? app(AccessControlService::class)->applyDocumentScope(Document::query(), $user)
                ->where('client_id', $inquiry->client_id)
                ->latest('id')
                ->limit(60)
                ->get(['id','name','flow_job_id','task_id','client_id'])
            : collect();
        $taskDocumentModalTask = $detailSectionsReady['taskflow'] && $this->showTaskDocumentModal && $this->taskDocumentModalTaskId
            ? $inquiry->tasks->firstWhere('id', (int) $this->taskDocumentModalTaskId)
            : null;
        $availableTaskDocuments = $this->showTaskDocumentModal
            && $this->taskDocumentSource === 'existing'
            && $user->canModule('documents', 'link')
            ? app(AccessControlService::class)->applyDocumentScope(Document::query(), $user)
                ->where('client_id', $inquiry->client_id)
                ->latest('id')
                ->limit(80)
                ->get(['id', 'name', 'flow_job_id', 'task_id', 'client_id', 'size', 'mime_type'])
            : collect();
        // Inquiry task management is inline on the workflow tab. Avoid loading
        // task documents/comments into a modal on every task deep-link/render.
        $task = null;
        $canEditInquiry = $detailQuery->canEditVisible($user, $inquiry);
        $canManageInquiryRecord = $canEditInquiry && ! $inquiry->result;
        // Keep overview editing aligned with the same current-task rule used by
        // the Inquiry list: furthest started open task, otherwise first queued.
        $activeTask = $inquiry->currentTask ?: $inquiry->tasks->first(fn (InquiryTask $row) => !$row->completed_at);
        $canEditActiveTask = $activeTask ? $detailQuery->canEditTask($user, $activeTask) : false;
        $workflowQuery = app(\App\Queries\Inquiries\InquiryWorkflowQuery::class);
        $inquiryTaskUi = [];
        $inquiryTaskStatusCompletion = [];
        if ($detailSectionsReady['taskflow']) {
            foreach ($inquiry->tasks as $taskRow) {
                $inquiryTaskUi[(int) $taskRow->id] = [
                    'canEdit' => $detailQuery->canEditTask($user, $taskRow),
                    'statusNeedsAttention' => $detailQuery->taskStatusNeedsAttention((string) $taskRow->status),
                ];
            }
            $taskStatusNames = $detailQuery->taskStatusOptions()
                ->merge($inquiry->tasks->pluck('status'))
                ->filter()
                ->unique(fn ($status) => mb_strtolower(trim((string) $status)));
            foreach ($taskStatusNames as $taskStatusName) {
                $name = (string) $taskStatusName;
                $inquiryTaskStatusCompletion[$name] = $workflowQuery->isCompletionStatus($name);
            }
        }

        $rfq = app(\App\Services\Inquiries\InquiryRfqService::class);
        $inquiryRfqSummary = $rfq->summary($inquiry);
        $rfqInvitations = collect();
        $rfqDefaultSuppliers = collect();
        $rfqSupplierCandidates = collect();
        $rfqAssignableProducts = collect();
        $rfqWorkspace = null;
        if ($rfqContext) {
            $rfqInvitations = $this->detailTab === 'rfq'
                ? $rfq->overviewInvitations($inquiry)
                : $rfq->invitations($inquiry);
            if ($this->detailTab === 'rfq') {
                $emailEnabled = $rfq->emailEnabled();
                $rfqWorkspace = \App\Support\InquiryRfqProductWorkspacePresenter::build(
                    $inquiry,
                    $inquiryProductMasters,
                    $inquiryProductAllSuppliers,
                    $rfqInvitations,
                    $emailEnabled,
                    $this->rfqTableSearch,
                    $this->rfqEmailStatusFilter,
                    $this->rfqSelectedProductSupplierKeys,
                    $this->rfqTablePage,
                );

                $rfqAssignableProducts = collect($inquiry->items ?? collect())
                    ->map(function ($item) use ($inquiryProductMasters): ?array {
                        $product = $inquiryProductMasters->get(mb_strtolower(trim((string) $item->item_name)));
                        if (! $product) return null;
                        return [
                            'id' => (int) $product->id,
                            'name' => (string) $item->item_name,
                            'code' => $product->productDisplayCode() ?: trim((string) $product->code),
                        ];
                    })
                    ->filter()
                    ->unique('id')
                    ->values();

                // Product-scoped assignment keeps the prototype behavior clear:
                // the same Supplier can be linked to more than one Product, while
                // already-assigned suppliers are removed from that Product picker.
                if ($this->showRfqSupplierPicker && $this->rfqSupplierProductId) {
                    $productId = (int) $this->rfqSupplierProductId;
                    $assignedIds = collect($inquiryProductAllSuppliers->get($productId, collect()))
                        ->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
                    $rfqSupplierCandidates = $rfq->supplierChoicesForWorkspace(
                        (int) $inquiry->workspace_id,
                        $this->rfqSupplierSearch,
                        50,
                        $assignedIds,
                    );
                }
            }
        }
        $canManageInquiryRfq = $canManageInquiryRecord;

        return [
            'mode' => 'detail',
            'inquiryDetailSectionsReady' => $detailSectionsReady,
            'selectedInquiry' => $inquiry,
            'selectedTask' => $task,
            'inquiryRfqSummary' => $inquiryRfqSummary,
            'rfqInvitations' => $rfqInvitations,
            'rfqDefaultSuppliers' => $rfqDefaultSuppliers,
            'rfqSupplierCandidates' => $rfqSupplierCandidates,
            'rfqAssignableProducts' => $rfqAssignableProducts,
            'rfqWorkspace' => $rfqWorkspace,
            'canManageInquiryRfq' => $canManageInquiryRfq,
            'canEditSuppliers' => $user->canModule('suppliers', 'edit'),
            'inquiryDocuments' => $documents,
            'inquiryActivities' => $activities,
            'inquiryMentionUsers' => $mentionUsers,
            'availableInquiryDocuments' => $availableInquiryDocuments,
            'taskDocumentModalTask' => $taskDocumentModalTask,
            'availableTaskDocuments' => $availableTaskDocuments,
            'canLinkDocuments' => $user->canModule('documents', 'link'),
            'canCreateDocuments' => $user->canModule('documents', 'create'),
            'canDeleteDocuments' => $user->canModule('documents', 'delete'),
            'canExportDocuments' => $user->canModule('documents', 'export'),
            'canAssignInquiry' => $user->canModule('inquiries', 'assign') || app(AccessControlService::class)->isInquiryCreator($user, $inquiry),
            'canEditInquiry' => $canEditInquiry,
            'canViewInquiryProducts' => $canViewInquiryProducts,
            'canCreateInquiryProducts' => $canManageInquiryRecord && $canViewInquiryProducts && $access->can($user, 'catalog_products', 'create'),
            'canEditInquiryProducts' => $canManageInquiryRecord && $canViewInquiryProducts && $access->can($user, 'catalog_products', 'edit'),
            'canDeleteInquiryProducts' => $canManageInquiryRecord && $canViewInquiryProducts && $access->can($user, 'catalog_products', 'delete'),
            'inquiryProductMasters' => $inquiryProductMasters,
            'inquiryProductSuppliers' => $inquiryProductSuppliers,
            'inquiryProductAllSuppliers' => $inquiryProductAllSuppliers,
            'inquiryProductRfqOverview' => $inquiryProductRfqOverview,
            'inquiryCurrencySymbol' => $inquiryCurrencySymbol,
            'inquiryProductSearchResults' => $inquiryProductSearchResults,
            'inquiryProductSearchSuppliers' => $inquiryProductSearchSuppliers,
            'inquiryProductResultTotal' => $inquiryProductResultTotal,
            'inquiryProductSelectedProduct' => $inquiryProductSelectedProduct,
            'inquiryProductSelectedSupplier' => $inquiryProductSelectedSupplier,
            'editInquiryProductSearchResults' => $editInquiryProductSearchResults,
            'editInquiryProductSearchSuppliers' => $editInquiryProductSearchSuppliers,
            'editInquiryProductResultTotal' => $editInquiryProductResultTotal,
            'editInquiryProductSelectedProduct' => $editInquiryProductSelectedProduct,
            'editInquiryProductSelectedSupplier' => $editInquiryProductSelectedSupplier,
            'canEditActiveTask' => $canEditActiveTask,
            'detailStatusColor' => $detailQuery->statusColor((string) $inquiry->status, (string) ($activeTask?->status ?: '')),
            'inquiryTaskUi' => $inquiryTaskUi,
            'inquiryTaskStatusCompletion' => $inquiryTaskStatusCompletion,
            'inquiryDefaultTaskStatus' => $workflowQuery->defaultTaskStatus(),
            'inquiryDefaultStatus' => $workflowQuery->defaultInquiryStatus(),
            'canAddInquiryTask' => app(AccessControlService::class)->canCreateInquiryTask($user, $inquiry) && !$inquiry->result,
            'inquiryPriorities' => $this->detailTab === 'overview' ? app(\App\Services\MasterDataService::class)->active('priority') : collect(),
            'inquiryTaskStatusOptions' => $this->detailTab === 'overview' && $detailSectionsReady['taskflow'] ? $detailQuery->taskStatusOptions() : collect(),
            'canCreateOrder' => $user->canModule('jobs', 'create'),
            'selectedTaskIsActive' => false,
            'selectedTaskCanEdit' => false,
        ];
    }
}
