<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\DeleteOrder;
use App\Actions\Orders\DeleteOrders;
use App\Exceptions\EmailDeliveryException;
use App\Queries\Orders\OrderListQuery;
use App\Queries\Orders\VisibleOrderQuery;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\Document;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\ArtworkUploadStagingService;
use App\Services\FilterOptionService;
use App\Services\DocumentService;
use App\Services\OrderDetailViewService;
use App\Services\OrderListPrototypeService;
use App\Services\OrderTaskSequenceService;
use App\Services\OrderWorkflowActionService;
use App\Services\Orders\OrderWorkflowEmailService;
use App\Services\TaskService;
use App\Support\AttachmentUpload;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use WithFileUploads;
    use WithPagination;

    public string $search = '';
    public string $client = '';
    public string $phase = '';
    public string $owner = '';
    public bool $holdOn = false;
    #[Url(as: 'metric', history: true, except: '')]
    public string $metricFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public int $dashboardScope = 0;
    public int $dashboardRangeDays = 0;
    public string $dashboardTeam = '';
    #[Url(as: 'import', history: true, except: 0)]
    public int $importBatchId = 0;
    public string $importBatchLabel = '';
    public int $perPage = 10;
    public string $stageQuick = 'all';
    public string $stageSupplier = '';
    public string $stageAssignee = '';
    public string $stageUrgency = '';
    public string $stageCarrier = '';
    public string $stageClient = '';
    public array $selectedOrderIds = [];
    public bool $showBulkDeleteConfirm = false;

    // CHANGE 2026-08-24: phase-wise list actions now reuse the exact Order
    // Details workflow engine and modal components instead of redirecting to
    // the Order Details page before the user can perform the required action.
    public ?int $listActionOrderId = null;
    public bool $showOrderWorkflowActionModal = false;
    public ?int $orderWorkflowActionTaskId = null;
    public string $orderWorkflowActionComment = '';
    /** Legacy single revision attachment state kept for non-revision compatibility. */
    public $orderWorkflowActionAttachment = null;
    /** Per-artwork required-change text keyed by source document id. */
    public array $orderWorkflowActionRevisionComments = [];
    /** Per-artwork supporting uploads keyed by source document id. */
    public array $orderWorkflowActionRevisionAttachments = [];
    public string $orderWorkflowActionStep = 'main';
    /** @var array<string,mixed> */
    public array $orderWorkflowActionPayload = [];
    /** Expensive workflow modal email/invoice preview snapshot; prepared outside Blade. */
    public array $orderWorkflowActionModalPreview = [];
    public bool $orderWorkflowEmailFallback = false;
    public string $orderWorkflowEmailFallbackMessage = '';
    public int $orderWorkflowEmailFallbackAttempts = 0;

    public bool $showOverviewTaskDocumentModal = false;
    public ?int $overviewTaskDocumentModalTaskId = null;
    public string $overviewTaskDocumentSource = 'upload';
    /** Files selected in the Order workflow upload modal. */
    public array $overviewTaskDocumentUpload = [];
    public array $overviewTaskRevisionUpload = [];
    /** Completed chunk-staged Artwork files; payload bytes never enter Livewire state. */
    public array $overviewTaskStagedUploads = [];
    /** Completed chunk-staged selective replacements keyed by source document id. */
    public array $overviewTaskStagedRevisionUploads = [];
    /** Artwork files selected for replacement in an active revision upload. */
    public array $overviewTaskRevisionDocumentIds = [];
    public ?int $overviewTaskExistingDocumentId = null;
    public string $overviewTaskDocumentNote = '';

    public function mount(): void
    {
        $this->search = trim((string) request('search', ''));
        $this->client = $this->numericFilterFromRequest('client');
        $this->phase = $this->numericFilterFromRequest('phase');
        $this->owner = $this->numericFilterFromRequest('owner');
        $this->holdOn = (int) request('hold_on', 0) === 1;
        $this->metricFilter = trim((string) request('metric', $this->metricFilter));
        if (! in_array($this->metricFilter, ['', 'createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completed', 'completedThisWeek', 'attention', 'dashboardActive', 'dashboardAttention', 'dashboardOverdueTasks'], true)) {
            $this->metricFilter = '';
        }
        $this->dateFrom = $this->normalizeDateFilter((string) request('date_from', ''));
        $this->dateTo = $this->normalizeDateFilter((string) request('date_to', ''));
        $this->normalizeDateRange('from');
        $this->dashboardScope = (int) request('dashboard_scope', 0) === 1 ? 1 : 0;
        $requestedDashboardRange = (int) request('dashboard_range', 0);
        $this->dashboardRangeDays = in_array($requestedDashboardRange, [1, 7, 30], true) ? $requestedDashboardRange : 0;
        $this->dashboardTeam = $this->numericFilterFromRequest('dashboard_team');

        // A dashboard deep link is valid only with an explicit local date range.
        // Falling back to the normal Orders semantics avoids a hidden updated_at
        // filter if somebody manually removes the dates from the URL.
        if ($this->dashboardScope === 1 && ($this->dateFrom === '' || $this->dateTo === '')) {
            $this->dashboardScope = 0;
            $this->dashboardRangeDays = 0;
            $this->dashboardTeam = '';
        }

        $this->importBatchId = max(0, (int) request('import', $this->importBatchId));
        if ($this->importBatchId > 0) {
            $this->importBatchLabel = app(OrderListQuery::class)->bulkImportNumber($this->importBatchId) ?? '';
            if ($this->importBatchLabel === '') {
                $this->importBatchId = 0;
            }
        }

        if ($this->importBatchId > 0) {
            // A completed import opens as its own deterministic result set.
            $this->clearListFiltersExcept('importBatch');
        }
    }

    public function updatedSearch(): void
    {
        $this->metricFilter = '';
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedClient(): void
    {
        $this->client = $this->normalizeNumericFilter($this->client);
        $this->metricFilter = '';
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedPhase(): void
    {
        $this->phase = $this->normalizeNumericFilter($this->phase);
        $this->metricFilter = '';
        $this->resetStageSpecificFilters();
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->owner = $this->normalizeNumericFilter($this->owner);
        $this->metricFilter = '';
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedHoldOn(): void
    {
        $this->metricFilter = '';
        $this->resetOrderSelection();
        $this->resetPage();
    }

    /**
     * Commit the Orders-list owner filter through an explicit Livewire action.
     *
     * The shared remote selector updates its label optimistically. On this
     * high-traffic list that optimistic Alpine state could survive even when a
     * deferred/stale Livewire property update lost the race with another list
     * request, leaving the dropdown showing a user while the query still used
     * owner = "".  Use a deterministic action for this filter so the visible
     * selection and the server-side query state are always committed together.
     */
    public function applyOwnerFilter(string $property, string|int|null $value = null): void
    {
        abort_unless($property === 'owner', 422);

        $this->owner = $this->normalizeNumericFilter((string) ($value ?? ''));
        $this->metricFilter = '';
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->dateFrom = $this->normalizeDateFilter($this->dateFrom);
        $this->clearListFiltersExcept('dateRange');
        $this->normalizeDateRange('from');
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->dateTo = $this->normalizeDateFilter($this->dateTo);
        $this->clearListFiltersExcept('dateRange');
        $this->normalizeDateRange('to');
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function updatedStageSupplier(): void { $this->stageSupplier = $this->normalizeNumericFilter($this->stageSupplier); $this->resetPage(); }
    public function updatedStageAssignee(): void { $this->stageAssignee = $this->normalizeNumericFilter($this->stageAssignee); $this->resetPage(); }
    public function updatedStageUrgency(): void { $this->stageUrgency = $this->normalizeNumericFilter($this->stageUrgency); $this->resetPage(); }
    public function updatedStageClient(): void { $this->stageClient = $this->normalizeNumericFilter($this->stageClient); $this->resetPage(); }
    public function updatedStageCarrier(): void { $this->stageCarrier = trim($this->stageCarrier); $this->resetPage(); }

    public function selectStage(?int $phaseId = null): void
    {
        $this->phase = $phaseId && $phaseId > 0 ? (string) $phaseId : '';
        $this->metricFilter = '';
        $this->resetStageSpecificFilters();
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function setStageQuick(string $quick): void
    {
        $allowed = collect(OrderListPrototypeService::QUICK_FILTERS)->flatMap(fn ($filters) => array_keys($filters));
        if (! $allowed->contains($quick)) return;
        $this->stageQuick = $quick;
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function clearStageSpecificFilters(): void
    {
        $this->resetStageSpecificFilters();
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        if ($this->search === '') {
            return;
        }

        $this->search = '';
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function setMetricFilter(string $metric): void
    {
        if (! in_array($metric, ['createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completed', 'completedThisWeek', 'attention'], true)) {
            return;
        }

        $nextMetric = $this->metricFilter === $metric ? '' : $metric;
        $this->metricFilter = $nextMetric;
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->client = '';
        $this->phase = '';
        $this->owner = '';
        $this->holdOn = false;
        $this->metricFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->dashboardScope = 0;
        $this->dashboardRangeDays = 0;
        $this->dashboardTeam = '';
        $this->importBatchId = 0;
        $this->importBatchLabel = '';
        $this->resetStageSpecificFilters();
        $this->resetOrderSelection();
        $this->resetPage();
    }

    public function openInvoiceAndPayment(int $id): void
    {
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'finance', 'view'), 403);

        // Confirm the Order is inside the current user's visible Order scope
        // before navigating to its finance section.
        $job = app(OrderListQuery::class)->visible($user, $id);

        $this->redirectRoute('jobs.index', [
            'open' => $job->id,
            'tab' => 'finance',
        ], navigate: true);
    }

    public function deleteOrder(int $id): void
    {
        $job = app(DeleteOrder::class)->handle(auth()->user(), $id);
        $this->selectedOrderIds = collect($this->selectedOrderIds)
            ->map(fn ($value) => (int) $value)
            ->reject(fn ($value) => $value === $id)
            ->values()
            ->all();
        $this->resetPage();

        session()->flash('success', $job->displayOrderNumber().' deleted successfully.');
    }

    public function toggleOrderSelection(int $id): void
    {
        $id = (int) $id;
        if ($id < 1 || ! app(OrderListQuery::class)->exists(auth()->user(), $id)) {
            return;
        }

        $selected = collect($this->selectedOrderIds)
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique();

        $this->selectedOrderIds = $selected->contains($id)
            ? $selected->reject(fn ($value) => $value === $id)->values()->all()
            : $selected->push($id)->unique()->values()->all();
    }

    public function toggleOrderPageSelection(array $ids, bool $checked): void
    {
        $ids = collect($ids)
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->showBulkDeleteConfirm = false;
            return;
        }

        $visibleIds = app(OrderListQuery::class)->visibleIds(auth()->user(), $ids);

        $selected = collect($this->selectedOrderIds)
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique();

        $this->selectedOrderIds = $checked
            ? $selected->concat($visibleIds)->unique()->values()->all()
            : $selected->reject(fn ($value) => $visibleIds->contains($value))->values()->all();
    }

    public function clearOrderSelection(): void
    {
        $this->resetOrderSelection();
    }

    public function openBulkDeleteConfirmation(): void
    {
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'jobs', 'delete'), 403);

        $ids = collect($this->selectedOrderIds)
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->showBulkDeleteConfirm = false;
            return;
        }

        // Keep only Orders that are still inside the user's current visible scope.
        $visibleIds = app(OrderListQuery::class)->visibleIds($user, $ids);

        $this->selectedOrderIds = $visibleIds->all();
        $this->showBulkDeleteConfirm = $visibleIds->isNotEmpty();
    }

    public function closeBulkDeleteConfirmation(): void
    {
        $this->showBulkDeleteConfirm = false;
    }

    public function bulkDeleteOrders(): void
    {
        $user = auth()->user();
        abort_unless(app(AccessControlService::class)->can($user, 'jobs', 'delete'), 403);

        $ids = collect($this->selectedOrderIds)
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->showBulkDeleteConfirm = false;
            return;
        }

        $deletedCount = app(DeleteOrders::class)->handle($user, $ids);

        if ($deletedCount === 0) {
            $this->resetOrderSelection();
            $this->showBulkDeleteConfirm = false;
            return;
        }
        $this->showBulkDeleteConfirm = false;
        $this->resetOrderSelection();
        $this->resetPage();

        session()->flash(
            'success',
            $deletedCount.' '.\Illuminate\Support\Str::plural('order', $deletedCount).' deleted successfully.'
        );
    }

    /**
     * CHANGE 2026-08-24:
     * Execute the phase-list Next Action in place. The descriptor, sequence
     * validation and workflow mutation all come from the same services used by
     * Order Details, so list actions cannot drift from the detail-page logic.
     */
    public function openListWorkflowAction(int $orderId, int $taskId): void
    {
        $task = $this->editableListWorkflowTask($orderId, $taskId, [
            'job.client',
            'job.items',
            'job.phase',
            'setupTemplate',
            'documents',
            'links',
        ]);

        app(OrderTaskSequenceService::class)->assertStatusActionable($task);

        $workflowActions = app(OrderWorkflowActionService::class);
        $hasEvidence = $task->documents->isNotEmpty() || $task->links->isNotEmpty();
        $descriptor = $workflowActions->descriptor($task, $hasEvidence);
        $interaction = (string) ($descriptor['interaction'] ?? $descriptor['type'] ?? 'modal');

        $this->listActionOrderId = $orderId;

        if ($interaction === 'document') {
            $this->openListTaskDocumentModal($task);
            return;
        }

        if ($interaction === 'direct') {
            $decision = ($descriptor['key'] ?? null) === 'SHIP_LABEL' ? 'generate' : 'confirm';
            $workflowActions->perform($task, auth()->user(), $decision);
            $this->listActionOrderId = null;
            session()->flash('success', 'Order workflow updated.');
            return;
        }

        $this->showOverviewTaskDocumentModal = false;
        $this->orderWorkflowActionTaskId = $taskId;
        $this->orderWorkflowActionComment = '';
        $this->orderWorkflowActionAttachment = null;
        $this->orderWorkflowActionRevisionComments = [];
        $this->orderWorkflowActionRevisionAttachments = [];
        $this->orderWorkflowActionStep = 'main';
        $this->orderWorkflowActionPayload = $workflowActions->initialPayload($task, $task->job);
        $this->orderWorkflowActionModalPreview = app(\App\Services\Orders\OrderWorkflowActionModalPreviewService::class)
            ->snapshot($task, $this->orderWorkflowActionPayload, false);
        $this->resetOrderWorkflowEmailFallbackState();

        if (in_array($descriptor['key'] ?? null, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            $failure = $this->orderWorkflowEmailFallbackMarker($task);
            if ($failure) {
                $this->showOrderWorkflowEmailFallback($descriptor['key'], (int) ($failure['attempts'] ?? 3));
            }
        }

        $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionAttachment', 'orderWorkflowActionRevisionComments', 'orderWorkflowActionRevisionAttachments', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
        $this->showOrderWorkflowActionModal = true;
    }

    public function closeOrderWorkflowAction(): void
    {
        $this->showOrderWorkflowActionModal = false;
        $this->orderWorkflowActionTaskId = null;
        $this->orderWorkflowActionComment = '';
        $this->orderWorkflowActionAttachment = null;
        $this->orderWorkflowActionRevisionComments = [];
        $this->orderWorkflowActionRevisionAttachments = [];
        $this->orderWorkflowActionStep = 'main';
        $this->orderWorkflowActionPayload = [];
        $this->orderWorkflowActionModalPreview = [];
        $this->listActionOrderId = null;
        $this->resetOrderWorkflowEmailFallbackState();
        $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionAttachment', 'orderWorkflowActionRevisionComments', 'orderWorkflowActionRevisionAttachments', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
    }

    /** Load the exact email body only after the list action modal shell is visible. */
    public function loadOrderWorkflowActionEmailPreview(): void
    {
        if (! $this->showOrderWorkflowActionModal || ! $this->listActionOrderId || ! $this->orderWorkflowActionTaskId) {
            return;
        }

        $previewService = app(\App\Services\Orders\OrderWorkflowActionModalPreviewService::class);
        if (! $previewService->requiresEmailPreview($this->orderWorkflowActionModalPreview)) {
            return;
        }

        $task = $this->editableListWorkflowTask(
            (int) $this->listActionOrderId,
            (int) $this->orderWorkflowActionTaskId,
            ['setupTemplate', 'job.client', 'job.owner', 'job.coordinator', 'job.items'],
        );

        $this->orderWorkflowActionModalPreview = $previewService
            ->snapshot($task, $this->orderWorkflowActionPayload, true);
    }

    /** Refresh only selection-dependent email previews, not every modal rerender. */
    public function updatedOrderWorkflowActionPayload(mixed $value, mixed $key = null): void
    {
        if (! in_array((string) $key, ['to_email', 'cc_emails', 'to_emails', 'customer_comment'], true)) {
            return;
        }

        $this->loadOrderWorkflowActionEmailPreview();
    }

    public function submitOrderWorkflowAction(string $decision = 'confirm'): void
    {
        // Ignore a stale duplicate submit after the modal has already closed.
        // This mirrors Order Details and prevents a harmless UI race from
        // becoming a user-facing HTTP 422 response.
        if (! $this->showOrderWorkflowActionModal || ! $this->listActionOrderId || ! $this->orderWorkflowActionTaskId) {
            return;
        }

        $task = $this->editableListWorkflowTask(
            (int) $this->listActionOrderId,
            (int) $this->orderWorkflowActionTaskId,
            ['job.client', 'job.items', 'job.phase', 'setupTemplate'],
        );

        $workflowActions = app(OrderWorkflowActionService::class);
        $key = $workflowActions->automationKey($task);

        // Keep the same nested Artwork / issue dialogs used by Order Details.
        if ($this->orderWorkflowActionStep === 'main' && $decision === 'revise'
            && in_array($key, ['ART_INTERNAL_REVIEW', 'ART_CLIENT_ERP_DECISION'], true)) {
            $this->orderWorkflowActionStep = 'revision';
            $this->orderWorkflowActionComment = '';
            $this->orderWorkflowActionAttachment = null;
            $this->orderWorkflowActionRevisionComments = [];
            $this->orderWorkflowActionRevisionAttachments = [];
            $this->orderWorkflowActionPayload['revision_document_ids'] = [];
            $this->orderWorkflowActionPayload['revision_items'] = [];
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionAttachment', 'orderWorkflowActionRevisionComments', 'orderWorkflowActionRevisionAttachments', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }

        if ($this->orderWorkflowActionStep === 'main' && $decision === 'issue'
            && in_array($key, ['PROD_ISSUE', 'QC_CHECK'], true)) {
            $this->orderWorkflowActionStep = 'issue';
            $this->orderWorkflowActionComment = '';
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }

        if ($key === 'ART_CLIENT_ERP_DECISION' && $decision === 'approved' && $this->orderWorkflowActionStep === 'main') {
            $this->orderWorkflowActionStep = 'sample';
            $this->orderWorkflowActionComment = '';
            $this->resetValidation(['orderWorkflowActionComment', 'orderWorkflowActionPayload', 'orderWorkflowActionEmail']);
            return;
        }

        if ($key === 'ART_CLIENT_ERP_DECISION' && $this->orderWorkflowActionStep === 'sample') {
            if (! in_array($decision, ['sample_yes', 'sample_no'], true)) {
                return;
            }

            $decision = $decision === 'sample_yes' ? 'sample' : 'confirm';
        }

        if (in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            $this->resetOrderWorkflowEmailFallbackState();
            $this->forgetOrderWorkflowEmailFallbackMarker($task);
        }

        $isArtworkRevisionSubmission = $this->orderWorkflowActionStep === 'revision'
            && $decision === 'revise'
            && in_array($key, ['ART_INTERNAL_REVIEW', 'ART_CLIENT_ERP_DECISION'], true);
        $revisionAttachments = [];
        if ($isArtworkRevisionSubmission) {
            $this->validate([
                'orderWorkflowActionPayload.revision_document_ids' => ['required', 'array', 'min:1'],
                'orderWorkflowActionPayload.revision_document_ids.*' => ['integer', 'distinct'],
            ], [
                'orderWorkflowActionPayload.revision_document_ids.required' => 'Select at least one artwork file that needs revision.',
                'orderWorkflowActionPayload.revision_document_ids.min' => 'Select at least one artwork file that needs revision.',
            ]);

            $revisionIds = collect($this->orderWorkflowActionPayload['revision_document_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();
            $rules = [];
            $messages = [];
            foreach ($revisionIds as $documentId) {
                $rules['orderWorkflowActionRevisionComments.'.$documentId] = ['required', 'string', 'max:10000'];
                $rules['orderWorkflowActionRevisionAttachments.'.$documentId] = ['nullable', 'array', 'max:50'];
                $rules['orderWorkflowActionRevisionAttachments.'.$documentId.'.*'] = AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 409600);
                $messages['orderWorkflowActionRevisionComments.'.$documentId.'.required'] = 'Describe the required change for this artwork.';
                $messages['orderWorkflowActionRevisionAttachments.'.$documentId.'.max'] = 'You can attach a maximum of 50 supporting files to each artwork.';
                $messages['orderWorkflowActionRevisionAttachments.'.$documentId.'.*.max'] = 'Each supporting file must be 400 MB or smaller.';
            }
            if ($rules !== []) $this->validate($rules, $messages);

            $this->orderWorkflowActionPayload['revision_items'] = $revisionIds->map(fn ($documentId) => [
                'document_id' => $documentId,
                'comment' => (string) ($this->orderWorkflowActionRevisionComments[$documentId] ?? ''),
            ])->all();
            $revisionAttachments = $revisionIds->mapWithKeys(fn ($documentId) => [
                $documentId => array_values(array_filter((array) ($this->orderWorkflowActionRevisionAttachments[$documentId] ?? []))),
            ])->all();
        }

        try {
            $workflowActions->perform(
                $task,
                auth()->user(),
                $decision,
                $this->orderWorkflowActionComment,
                $this->orderWorkflowActionPayload,
                $isArtworkRevisionSubmission ? $revisionAttachments : [],
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if (! $isArtworkRevisionSubmission || $exception->getStatusCode() !== 422) {
                throw $exception;
            }

            $message = trim((string) $exception->getMessage());
            $this->addError(
                'orderWorkflowActionRevisionAttachments',
                $message !== '' ? $message : 'One of the supporting files could not be verified. Re-export it and try again.',
            );
            return;
        } catch (EmailDeliveryException $exception) {
            if (! in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
                throw $exception;
            }

            $preview = app(OrderWorkflowEmailService::class)->preview($task, auth()->user(), $this->orderWorkflowActionPayload);
            $trackingId = '';
            if (preg_match('/Reference:\s*([A-Za-z0-9-]+)/', $exception->getMessage(), $matches) === 1) {
                $trackingId = (string) ($matches[1] ?? '');
            }
            $previewPrimary = collect($preview['recipients'] ?? [])->first();
            $previewCc = collect($preview['cc_recipients'] ?? []);
            $failure = [
                'task_id' => (int) $task->id,
                'flow_job_id' => (int) $task->flow_job_id,
                'handoff_key' => (string) $key,
                'document_id' => (int) ($preview['document_id'] ?? 0),
                'document_name' => (string) ($preview['document_name'] ?? ''),
                'attempts' => 3,
                'tracking_id' => $trackingId,
                'primary_recipient_user_id' => ($previewPrimary && ! ($previewPrimary['external'] ?? false))
                    ? (int) ($previewPrimary['id'] ?? 0)
                    : 0,
                'assignment_user_id' => (int) ($preview['assignment_user_id'] ?? 0),
                'external_primary_recipient' => ($previewPrimary && ($previewPrimary['external'] ?? false))
                    ? [
                        'name' => trim((string) ($previewPrimary['name'] ?? 'External recipient')),
                        'email' => trim((string) ($previewPrimary['email'] ?? '')),
                    ]
                    : null,
                'cc_recipient_user_ids' => $previewCc
                    ->filter(fn ($recipient) => ! ($recipient['external'] ?? false))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->values()
                    ->all(),
                'external_cc_emails' => $previewCc
                    ->filter(fn ($recipient) => (bool) ($recipient['external'] ?? false))
                    ->pluck('email')
                    ->filter()
                    ->values()
                    ->implode(', '),
                'failed_at' => now()->toIso8601String(),
            ];
            session()->put($this->orderWorkflowEmailFallbackSessionKey($task), $failure);

            $this->showOrderWorkflowEmailFallback($key, 3);
            $this->resetValidation('orderWorkflowActionEmail');
            return;
        }

        if (in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
            $this->forgetOrderWorkflowEmailFallbackMarker($task);
        }

        $successMessage = match ($key) {
            'NEW_SEND_PO_ARTWORK' => 'Purchase Order emailed to the Artwork Team.',
            'ART_SEND_ORDER_TEAM' => 'Artwork emailed to the Order Team.',
            default => 'Order workflow updated.',
        };

        if ($key === 'ART_INTERNAL_REVIEW' && $decision === 'confirm') {
            try {
                $warning = app(\App\Services\Reminders\SupplierArtworkReminderService::class)
                    ->missingSupplierEmailWarning(app(\App\Services\SetupContext::class)->workspaceId(), (int) $task->flow_job_id);
                if ($warning) $successMessage .= ' '.$warning;
            } catch (\Throwable $exception) {
                report($exception); // Email-status feedback cannot undo the completed workflow.
            }
        }

        $this->closeOrderWorkflowAction();
        session()->flash('success', $successMessage);
    }

    public function completeOrderWorkflowEmailTaskAfterFailure(): void
    {
        abort_unless($this->listActionOrderId && $this->orderWorkflowActionTaskId, 422);

        $task = $this->editableListWorkflowTask(
            (int) $this->listActionOrderId,
            (int) $this->orderWorkflowActionTaskId,
            ['job.client', 'job.items', 'job.phase', 'setupTemplate'],
        );

        $workflowActions = app(OrderWorkflowActionService::class);
        $key = $workflowActions->automationKey($task);
        abort_unless(in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true), 422);

        $failure = $this->orderWorkflowEmailFallbackMarker($task);
        if (! $failure) {
            $this->resetOrderWorkflowEmailFallbackState();
            $this->addError('orderWorkflowActionEmail', 'Manual completion is available only after the email service has failed three delivery attempts.');
            return;
        }

        $workflowActions->completeEmailHandoffAfterFailure($task, auth()->user(), $failure);
        $this->forgetOrderWorkflowEmailFallbackMarker($task);

        $attachmentLabel = $key === 'ART_SEND_ORDER_TEAM' ? 'artwork' : 'Purchase Order';
        $this->closeOrderWorkflowAction();
        session()->flash('success', 'Task completed manually. Please send the '.$attachmentLabel.' outside FlowTrack using the downloaded file.');
    }

    private function showOrderWorkflowEmailFallback(?string $key, int $attempts = 3): void
    {
        $attempts = max(3, $attempts);
        $attachmentLabel = $key === 'ART_SEND_ORDER_TEAM' ? 'artwork' : 'Purchase Order';

        $this->orderWorkflowEmailFallback = true;
        $this->orderWorkflowEmailFallbackAttempts = $attempts;
        $this->orderWorkflowEmailFallbackMessage = 'Due to some technical issue, the email could not be sent after '.$attempts.' attempts. Please download the '.$attachmentLabel.' and send it manually. After sending it manually, you can complete this task to continue the workflow.';
    }

    public function removeOrderWorkflowActionAttachment(): void
    {
        $this->orderWorkflowActionAttachment = null;
        $this->resetValidation('orderWorkflowActionAttachment');
    }

    public function removeOrderWorkflowActionRevisionAttachment(int $documentId, int $index): void
    {
        if (! isset($this->orderWorkflowActionRevisionAttachments[$documentId][$index])) return;

        unset($this->orderWorkflowActionRevisionAttachments[$documentId][$index]);
        $this->orderWorkflowActionRevisionAttachments[$documentId] = array_values(
            $this->orderWorkflowActionRevisionAttachments[$documentId],
        );
        $this->resetValidation([
            'orderWorkflowActionRevisionAttachments.'.$documentId,
            'orderWorkflowActionRevisionAttachments.'.$documentId.'.*',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function orderWorkflowEmailFallbackMarker(Task $task): ?array
    {
        $value = session()->get($this->orderWorkflowEmailFallbackSessionKey($task));
        return is_array($value) ? $value : null;
    }

    private function forgetOrderWorkflowEmailFallbackMarker(Task $task): void
    {
        session()->forget($this->orderWorkflowEmailFallbackSessionKey($task));
    }

    private function orderWorkflowEmailFallbackSessionKey(Task $task): string
    {
        return 'order_workflow_email_fallback.'.(int) auth()->id().'.'.(int) $task->id;
    }

    private function resetOrderWorkflowEmailFallbackState(): void
    {
        $this->orderWorkflowEmailFallback = false;
        $this->orderWorkflowEmailFallbackMessage = '';
        $this->orderWorkflowEmailFallbackAttempts = 0;
    }

    /** Initialize the same file-upload action modal used on Order Details. */
    private function openListTaskDocumentModal(Task $task): void
    {
        $this->discardOverviewTaskStagedUploads();
        $canCreate = auth()->user()->canModule('documents', 'create');
        $canLink = auth()->user()->canModule('documents', 'link');
        abort_unless($canCreate || $canLink, 403, 'Your role cannot add documents.');

        $this->showOrderWorkflowActionModal = false;
        $this->orderWorkflowActionTaskId = null;
        $this->overviewTaskDocumentModalTaskId = (int) $task->id;
        $this->overviewTaskDocumentSource = $canCreate ? 'upload' : 'existing';
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $pendingArtworkRevision = app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_PREPARE_UPLOAD'
            ? app(DocumentService::class)->pendingArtworkRevision($task)
            : ['active' => false, 'document_ids' => []];
        $this->overviewTaskRevisionDocumentIds = (bool) ($pendingArtworkRevision['active'] ?? false)
            ? array_values(array_map('intval', $pendingArtworkRevision['document_ids'] ?? []))
            : [];
        $this->overviewTaskExistingDocumentId = null;
        $this->overviewTaskDocumentNote = '';
        $this->resetValidation([
            'overviewTaskDocumentUpload',
            'overviewTaskRevisionUpload',
            'overviewTaskRevisionDocumentIds',
            'overviewTaskExistingDocumentId',
            'overviewTaskDocumentNote',
        ]);
        $this->showOverviewTaskDocumentModal = true;
    }

    public function closeOverviewTaskDocumentModal(): void
    {
        $this->discardOverviewTaskStagedUploads();
        $this->showOverviewTaskDocumentModal = false;
        $this->overviewTaskDocumentModalTaskId = null;
        $this->overviewTaskDocumentSource = 'upload';
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $this->overviewTaskRevisionDocumentIds = [];
        $this->overviewTaskExistingDocumentId = null;
        $this->overviewTaskDocumentNote = '';
        $this->listActionOrderId = null;
        $this->resetValidation([
            'overviewTaskDocumentUpload',
            'overviewTaskRevisionUpload',
            'overviewTaskRevisionDocumentIds',
            'overviewTaskExistingDocumentId',
            'overviewTaskDocumentNote',
        ]);
    }

    public function setOverviewTaskDocumentSource(string $source): void
    {
        $this->discardOverviewTaskStagedUploads();
        abort_unless(in_array($source, ['upload', 'existing'], true), 422);

        if ($source === 'upload') {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        } else {
            abort_unless(auth()->user()->canModule('documents', 'link'), 403);
        }

        $this->overviewTaskDocumentSource = $source;
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $this->overviewTaskExistingDocumentId = null;
        $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskRevisionUpload', 'overviewTaskExistingDocumentId']);
    }

    /** @param array<int,string> $tokens */
    public function registerOverviewTaskArtworkUploads(array $tokens): void
    {
        $task = $this->overviewArtworkUploadTask();
        $staging = app(ArtworkUploadStagingService::class);
        $existingTokens = collect($this->overviewTaskStagedUploads)->pluck('token')->map('strval')->all();

        foreach (array_values(array_unique(array_filter(array_map('strval', $tokens)))) as $token) {
            if (in_array($token, $existingTokens, true)) continue;
            abort_if(count($this->overviewTaskStagedUploads) >= 50, 422, 'You can upload a maximum of 50 artwork files at a time.');
            $descriptor = $staging->describe($token, auth()->user(), $task);
            abort_if(($descriptor['revision_document_id'] ?? null) !== null, 422, 'This staged file belongs to a selective artwork revision.');
            $this->overviewTaskStagedUploads[] = $descriptor;
            $existingTokens[] = $token;
        }

        $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskDocumentUpload.*']);
    }

    public function registerOverviewTaskArtworkRevisionUpload(int $documentId, string $token): void
    {
        $task = $this->overviewArtworkUploadTask();
        abort_unless(in_array($documentId, array_map('intval', $this->overviewTaskRevisionDocumentIds), true), 422, 'This artwork is not selected for revision.');

        $staging = app(ArtworkUploadStagingService::class);
        $descriptor = $staging->describe($token, auth()->user(), $task);
        abort_unless((int) ($descriptor['revision_document_id'] ?? 0) === $documentId, 422, 'This replacement belongs to a different artwork file.');

        $oldToken = (string) data_get($this->overviewTaskStagedRevisionUploads, $documentId.'.token', '');
        if ($oldToken !== '' && $oldToken !== $token) {
            $staging->discard($oldToken, auth()->user());
        }
        $this->overviewTaskStagedRevisionUploads[$documentId] = $descriptor;
        $this->resetValidation(['overviewTaskRevisionUpload', 'overviewTaskRevisionUpload.'.$documentId]);
    }

    private function overviewArtworkUploadTask(): Task
    {
        abort_unless($this->listActionOrderId && $this->overviewTaskDocumentModalTaskId, 422);
        $task = $this->editableListWorkflowTask(
            (int) $this->listActionOrderId,
            (int) $this->overviewTaskDocumentModalTaskId,
            ['job', 'documentCategory', 'setupTemplate.documentCategory'],
        );
        abort_unless(in_array(app(OrderWorkflowActionService::class)->automationKey($task), ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true), 422);
        return $task;
    }

    private function discardOverviewTaskStagedUploads(): void
    {
        if (! auth()->check()) return;
        $staging = app(ArtworkUploadStagingService::class);
        $tokens = collect($this->overviewTaskStagedUploads)
            ->concat(collect($this->overviewTaskStagedRevisionUploads)->values())
            ->pluck('token')
            ->map('strval')
            ->filter()
            ->unique();
        foreach ($tokens as $token) {
            $staging->discard($token, auth()->user());
        }
    }

    public function saveOverviewTaskDocument(): void
    {
        abort_unless($this->listActionOrderId && $this->overviewTaskDocumentModalTaskId, 422);

        $task = $this->editableListWorkflowTask(
            (int) $this->listActionOrderId,
            (int) $this->overviewTaskDocumentModalTaskId,
            ['job', 'documentCategory', 'setupTemplate.documentCategory'],
        );

        $this->validate([
            'overviewTaskDocumentSource' => ['required', Rule::in(['upload', 'existing'])],
            'overviewTaskDocumentNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = trim($this->overviewTaskDocumentNote);
        $note = $note !== '' ? $note : null;
        $documentService = app(DocumentService::class);

        if ($this->overviewTaskDocumentSource === 'upload') {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);
            $automationKey = app(OrderWorkflowActionService::class)->automationKey($task);
            $isArtworkUpload = in_array($automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true);
            $isPurchaseOrderUpload = $automationKey === 'NEW_UPLOAD_PO';
            $artworkRevision = $isArtworkUpload ? $documentService->pendingArtworkRevision($task) : ['active' => false, 'documents' => collect()];
            $isArtworkRevision = $isArtworkUpload && (bool) ($artworkRevision['active'] ?? false);
            $revisionDocuments = collect();
            $expectedRevisionCount = 0;
            if ($isArtworkRevision) {
                $revisionDocuments = collect($artworkRevision['documents'] ?? [])->values();
                $expectedRevisionCount = $revisionDocuments->count();

                $this->validate([
                    'overviewTaskRevisionDocumentIds' => ['required', 'array', 'min:1'],
                    'overviewTaskRevisionDocumentIds.*' => ['integer', 'distinct'],
                ], [
                    'overviewTaskRevisionDocumentIds.required' => 'No artwork is selected for this revision.',
                    'overviewTaskRevisionDocumentIds.min' => 'No artwork is selected for this revision.',
                ]);

                $expectedIds = $revisionDocuments->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $stagedIds = collect(array_keys($this->overviewTaskStagedRevisionUploads))->map(fn ($id) => (int) $id)->sort()->values()->all();
                if ($expectedRevisionCount < 1 || $expectedIds !== $stagedIds) {
                    $this->addError(
                        'overviewTaskRevisionUpload',
                        'Choose one completed replacement file under each of the '.$expectedRevisionCount.' selected artwork file'.($expectedRevisionCount === 1 ? '' : 's').'.',
                    );
                    return;
                }

                $artworkRevision = $documentService->updatePendingArtworkRevisionSelection(
                    $task,
                    $this->overviewTaskRevisionDocumentIds,
                );
            }

            $revisionFileCount = (bool) ($artworkRevision['active'] ?? false)
                ? collect($artworkRevision['documents'] ?? [])->count()
                : 0;
            $allowsMultiple = $isArtworkUpload || $isPurchaseOrderUpload || (bool) ($task->setupTemplate?->allow_multiple_documents ?? false);

            if (! $isArtworkRevision) {
                if ($isArtworkUpload) {
                    $stagedCount = count($this->overviewTaskStagedUploads);
                    if ($stagedCount < 1) {
                        $this->addError('overviewTaskDocumentUpload', 'Choose at least one artwork file to upload.');
                        return;
                    }
                    if ($stagedCount > 50) {
                        $this->addError('overviewTaskDocumentUpload', 'You can upload a maximum of 50 artwork files at a time.');
                        return;
                    }
                } else {
                    $uploadMaxKilobytes = AttachmentUpload::STANDARD_MAX_KILOBYTES;
                    $maxUploads = $allowsMultiple ? 10 : 1;
                    $maxUploadsMessage = $allowsMultiple
                        ? 'You can upload a maximum of '.$maxUploads.' files at a time.'
                        : 'Choose one file for this task.';

                    $this->validate([
                        'overviewTaskDocumentUpload' => ['required', 'array', 'min:1', 'max:'.$maxUploads],
                        'overviewTaskDocumentUpload.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, $uploadMaxKilobytes),
                    ], [
                        'overviewTaskDocumentUpload.max' => $maxUploadsMessage,
                        'overviewTaskDocumentUpload.*.max' => 'Each file must be 20 MB or smaller.',
                    ]);
                }
            }

            $staging = app(ArtworkUploadStagingService::class);
            $materializedPaths = [];
            $stagedTokens = [];
            $uploads = $isArtworkRevision ? $this->overviewTaskRevisionUpload : $this->overviewTaskDocumentUpload;

            try {
                if ($isArtworkUpload) {
                    if ($isArtworkRevision) {
                        foreach ($revisionDocuments as $revisionDocument) {
                            $documentId = (int) $revisionDocument->id;
                            $token = (string) data_get($this->overviewTaskStagedRevisionUploads, $documentId.'.token', '');
                            abort_if($token === '', 422, 'One replacement artwork upload is missing.');
                            $stagedTokens[$documentId] = $token;
                        }
                    } else {
                        $stagedTokens = collect($this->overviewTaskStagedUploads)
                            ->pluck('token')
                            ->map('strval')
                            ->filter()
                            ->values()
                            ->all();
                    }

                    $materialized = $staging->materialize($stagedTokens, auth()->user(), $task);
                    $uploads = $materialized['files'];
                    $materializedPaths = $materialized['temporary_paths'];
                }

                if ($revisionFileCount > 0) {
                    $documentService->storeArtworkRevision(
                        $uploads,
                        $task,
                        auth()->user(),
                        $note,
                    );
                } else {
                    $storeData = [
                        'flow_job_id' => $task->flow_job_id,
                        'client_id' => $task->job?->client_id,
                        'task_id' => $task->id,
                        'note' => $note,
                    ];

                    if ($documentService->taskHasRequirement($task)) {
                        $storeData['require_task_pack_requirement'] = true;
                    } else {
                        $storeData['category'] = 'Task attachment';
                    }

                    $documentService->storeMany($uploads, $storeData, auth()->user());
                }

                if ($isArtworkUpload) {
                    $staging->consume($stagedTokens, auth()->user());
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 422) {
                    throw $exception;
                }

                $message = trim((string) $exception->getMessage());
                $this->addError(
                    $isArtworkRevision ? 'overviewTaskRevisionUpload' : 'overviewTaskDocumentUpload',
                    $message !== '' ? $message : 'One of the selected files could not be verified. Re-export it and try again.',
                );
                return;
            } finally {
                $staging->releaseMaterialized($materializedPaths);
            }
        } else {
            abort_unless(auth()->user()->canModule('documents', 'link'), 403);
            $this->validate([
                'overviewTaskExistingDocumentId' => ['required', 'integer', 'exists:documents,id'],
            ]);

            $source = app(AccessControlService::class)
                ->applyDocumentScope(
                    Document::query()->whereKey((int) $this->overviewTaskExistingDocumentId),
                    auth()->user(),
                )
                ->firstOrFail();

            abort_unless(
                (int) $source->client_id === (int) $task->job?->client_id,
                403,
                'The selected document does not belong to this client.',
            );

            $documentService->linkExisting($source, $task, auth()->user(), true, $note);
        }

        // File-backed workflow actions complete/advance through the same hook
        // as the Order Details page after the document has persisted.
        app(OrderWorkflowActionService::class)->afterDocumentAdded($task->refresh(), auth()->user());

        $title = (string) $task->title;
        $this->closeOverviewTaskDocumentModal();
        session()->flash('success', 'Document added to '.$title.'.');
    }

    public function removeOverviewTaskDocumentUpload(int $index): void
    {
        $staging = app(ArtworkUploadStagingService::class);

        if (array_key_exists($index, $this->overviewTaskStagedRevisionUploads)) {
            $token = (string) data_get($this->overviewTaskStagedRevisionUploads, $index.'.token', '');
            if ($token !== '') $staging->discard($token, auth()->user());
            unset($this->overviewTaskStagedRevisionUploads[$index]);
            $this->resetValidation(['overviewTaskRevisionUpload', 'overviewTaskRevisionUpload.'.$index]);
            return;
        }

        if (array_key_exists($index, $this->overviewTaskStagedUploads)) {
            $token = (string) data_get($this->overviewTaskStagedUploads, $index.'.token', '');
            if ($token !== '') $staging->discard($token, auth()->user());
            unset($this->overviewTaskStagedUploads[$index]);
            $this->overviewTaskStagedUploads = array_values($this->overviewTaskStagedUploads);
            $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskDocumentUpload.*']);
            return;
        }

        if (array_key_exists($index, $this->overviewTaskRevisionUpload)) {
            unset($this->overviewTaskRevisionUpload[$index]);
            $this->resetValidation(['overviewTaskRevisionUpload', 'overviewTaskRevisionUpload.'.$index]);
            return;
        }

        if (! array_key_exists($index, $this->overviewTaskDocumentUpload)) return;

        unset($this->overviewTaskDocumentUpload[$index]);
        $this->overviewTaskDocumentUpload = array_values($this->overviewTaskDocumentUpload);
        $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskDocumentUpload.*']);
    }

    private function editableListWorkflowTask(int $orderId, int $taskId, array $with = []): Task
    {
        abort_unless($orderId > 0 && $taskId > 0, 422);

        // Verify the Order itself remains in the current user's list scope.
        app(OrderListQuery::class)->visible(auth()->user(), $orderId);

        $task = app(TaskService::class)
            ->visibleQuery(auth()->user())
            ->with($with)
            ->where('flow_job_id', $orderId)
            ->findOrFail($taskId);

        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        return $task;
    }

    public function render()
    {
        $user = auth()->user();
        $options = app(FilterOptionService::class);
        $list = app(OrderListQuery::class);
        $urgencies = $list->urgencyOptions();
        $filters = [
            'search' => $this->search,
            'client_id' => $this->filterId($this->client),
            'phase_id' => $this->filterId($this->phase),
            'owner_id' => $this->filterId($this->owner),
            'hold_on' => $this->holdOn,
            'metric' => $this->metricFilter,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'dashboard_scope' => $this->dashboardScope === 1,
            'dashboard_team_id' => $this->filterId($this->dashboardTeam),
            'import_id' => $this->importBatchId > 0 ? $this->importBatchId : null,
            'stage_quick' => $this->stageQuick,
            'stage_supplier_id' => $this->filterId($this->stageSupplier),
            'stage_assignee_id' => $this->filterId($this->stageAssignee),
            'stage_urgency_id' => $this->filterId($this->stageUrgency),
            'stage_carrier' => $this->stageCarrier,
            'stage_client_id' => $this->filterId($this->stageClient),
        ];
        $stages = $list->stagesForFilters($user, $filters);
        $jobs = $list->paginate($user, $filters, $stages, $this->perPage);
        $metrics = [
            'completed' => $list->completedCount($user, $filters, $stages),
        ];

        $selectedStage = $this->phase !== '' ? $stages->firstWhere('id', (int) $this->phase) : null;
        $stageSequence = (int) data_get($selectedStage, 'sequence', 0);

        // CHANGE 2026-08-24: hydrate one Order only while an inline list action
        // modal is open. Normal list renders keep the existing bounded queries.
        $listActionOrder = null;
        $listActionTask = null;
        $listActionContext = [];
        $listActionWorkflowModal = [];
        $listActionAvailableDocuments = collect();
        $listActionArtworkRevision = ['active' => false, 'documents' => collect(), 'retained_documents' => collect()];

        if ($this->listActionOrderId && ($this->showOrderWorkflowActionModal || $this->showOverviewTaskDocumentModal)) {
            $orderQuery = app(VisibleOrderQuery::class);
            $listActionOrder = $orderQuery->base($user, (int) $this->listActionOrderId);
            $orderQuery->loadTab($listActionOrder, $user, 'overview');

            $actionTaskId = $this->showOrderWorkflowActionModal
                ? $this->orderWorkflowActionTaskId
                : $this->overviewTaskDocumentModalTaskId;

            $listActionTask = $actionTaskId
                ? $listActionOrder->tasks->firstWhere('id', (int) $actionTaskId)
                : null;

            if ($listActionTask) {
                $listActionContext = app(OrderDetailViewService::class)->build($listActionOrder, $user, $urgencies);
                if ($this->showOverviewTaskDocumentModal
                    && app(OrderWorkflowActionService::class)->automationKey($listActionTask) === 'ART_PREPARE_UPLOAD') {
                    $listActionArtworkRevision = app(DocumentService::class)->pendingArtworkRevision($listActionTask);
                }
                $listActionWorkflowModal = data_get(
                    $listActionContext,
                    'taskActionModals.'.(int) $listActionTask->id,
                    [],
                );

                if ($this->showOverviewTaskDocumentModal && $this->overviewTaskDocumentSource === 'existing') {
                    $listActionAvailableDocuments = app(DocumentService::class)
                        ->query($user, ['client' => $listActionOrder->client_id])
                        ->with(['job:id,job_number', 'task:id,title'])
                        ->latest('id')
                        ->limit(60)
                        ->get();
                }
            }
        }

        $dashboardTeamLabel = '';
        if ($this->dashboardScope === 1 && $this->dashboardTeam !== '') {
            $dashboardTeamLabel = (string) data_get(
                $options->selectedOptions($user, 'departments', 'dashboard', [(int) $this->dashboardTeam])->first(),
                'label',
                '',
            );
        }

        return view('livewire.orders.index', [
            'jobs' => $jobs,
            'orderRows' => $list->rows($jobs, $urgencies),
            'orderStages' => $stages,
            'orderMetrics' => $metrics,
            'selectedStage' => $selectedStage,
            'stageQuickFilters' => OrderListPrototypeService::QUICK_FILTERS[$stageSequence] ?? ['all' => 'All'],
            // Priority 7: remote Orders filters fetch their recent/search
            // options only when opened. During normal list renders we resolve
            // just an already-selected row so the visible label survives page
            // refreshes, deep links and Livewire morphs without five page-one
            // option queries on every request.
            'clientFilterOptions' => $this->selectedFilterOptions($options, $user, 'clients', 'jobs', $this->client),
            'ownerFilterOptions' => $this->selectedFilterOptions($options, $user, 'users', 'order-list-user-filter', $this->owner),
            'stageAssigneeOptions' => $this->selectedFilterOptions($options, $user, 'users', 'order-list-user-filter', $this->stageAssignee),
            'stageClientFilterOptions' => $this->selectedFilterOptions($options, $user, 'clients', 'jobs', $this->stageClient),
            'supplierFilterOptions' => $this->selectedFilterOptions($options, $user, 'suppliers', 'order-list', $this->stageSupplier),
            'shipmentUrgencyOptions' => $urgencies,
            'dashboardScope' => $this->dashboardScope === 1,
            'dashboardRangeDays' => $this->dashboardRangeDays,
            'dashboardTeamLabel' => $dashboardTeamLabel,
            'listActionOrder' => $listActionOrder,
            'listActionTask' => $listActionTask,
            'listActionContext' => $listActionContext,
            'listActionWorkflowModal' => $listActionWorkflowModal,
            'listActionAvailableDocuments' => $listActionAvailableDocuments,
            'listActionArtworkRevision' => $listActionArtworkRevision,
        ]);
    }

    private function selectedFilterOptions(
        FilterOptionService $options,
        User $user,
        string $type,
        string $context,
        string $value,
    ): Collection {
        $selectedId = $this->filterId($value);

        if ($selectedId === null) {
            return collect();
        }

        return $options->selectedOptions($user, $type, $context, [$selectedId]);
    }

    private function clearListFiltersExcept(string $except): void
    {
        if ($except !== 'search') {
            $this->search = '';
        }
        if ($except !== 'client') {
            $this->client = '';
        }
        if ($except !== 'phase') {
            $this->phase = '';
        }
        if ($except !== 'owner') {
            $this->owner = '';
        }
        if ($except !== 'holdOn') {
            $this->holdOn = false;
        }
        if ($except !== 'dateRange') {
            $this->dateFrom = '';
            $this->dateTo = '';
            $this->dashboardScope = 0;
            $this->dashboardRangeDays = 0;
            $this->dashboardTeam = '';
        }
        if ($except !== 'importBatch') {
            $this->importBatchId = 0;
            $this->importBatchLabel = '';
        }

        $this->metricFilter = '';
    }

    private function clearToolbarFilters(): void
    {
        $this->search = '';
        $this->client = '';
        $this->phase = '';
        $this->owner = '';
        $this->holdOn = false;
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->dashboardScope = 0;
        $this->dashboardRangeDays = 0;
        $this->dashboardTeam = '';
        $this->importBatchId = 0;
        $this->importBatchLabel = '';
    }

    private function normalizeDateFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        try {
            $date = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            return '';
        }

        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function normalizeDateRange(string $changed): void
    {
        if ($this->dateFrom === '' || $this->dateTo === '' || $this->dateFrom <= $this->dateTo) {
            return;
        }

        if ($changed === 'to') {
            $this->dateFrom = $this->dateTo;
            return;
        }

        $this->dateTo = $this->dateFrom;
    }

    private function resetStageSpecificFilters(): void
    {
        $this->stageQuick = 'all';
        $this->stageSupplier = '';
        $this->stageAssignee = '';
        $this->stageUrgency = '';
        $this->stageCarrier = '';
        $this->stageClient = '';
    }

    private function resetOrderSelection(): void
    {
        $this->selectedOrderIds = [];
        $this->showBulkDeleteConfirm = false;
    }

    private function numericFilterFromRequest(string $key): string
    {
        return $this->normalizeNumericFilter((string) request($key, ''));
    }

    private function normalizeNumericFilter(string $value): string
    {
        $value = trim($value);

        return $value !== '' && ctype_digit($value) && (int) $value > 0
            ? (string) ((int) $value)
            : '';
    }

    private function filterId(string $value): ?int
    {
        return $value !== '' && ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : null;
    }
}
