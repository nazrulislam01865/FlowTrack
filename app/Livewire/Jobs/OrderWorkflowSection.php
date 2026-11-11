<?php

namespace App\Livewire\Jobs;

use App\Actions\Orders\AutoAdvanceOrder;
use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Jobs\Concerns\ManagesDetailProgressiveLoading;
use App\Livewire\Jobs\Concerns\ManagesOrderDocuments;
use App\Livewire\Jobs\Concerns\ManagesOrderShipments;
use App\Livewire\Jobs\Concerns\ManagesOrderTaskResources;
use App\Livewire\Jobs\Concerns\ManagesOrderTasks;
use App\Livewire\Jobs\Concerns\ManagesOrderWorkflow;
use App\Models\ClientShippingAddress;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\DocumentService;
use App\Services\MasterDataService;
use App\Services\LocationMasterDataService;
use App\Services\MentionService;
use App\Services\OrderArtworkEvidenceService;
use App\Services\OrderDetailViewService;
use App\Support\Performance\OrderBaselineRecorder;
use App\Services\OrderWorkflowBindingService;
use App\Support\BoardLaneResolver;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Isolated interactive Order workflow workspace.
 *
 * This deliberately reuses the proven workflow/task/shipment concerns so the
 * business rules stay identical while Livewire hydrates only workflow state.
 * The optimized three-level path renders the default stage in the initial
 * Order GET; the legacy viewport-triggered path remains available by flag.
 */
final class OrderWorkflowSection extends Component
{
    use HandlesInlineEdits;
    use ManagesOrderWorkflow;
    use ManagesOrderShipments;
    use ManagesOrderTaskResources;
    use ManagesOrderTasks;
    use ManagesOrderDocuments;
    use ManagesDetailProgressiveLoading;
    use WithFileUploads;

    public int $orderId;
    public bool $ready = false;

    /**
     * Same-request handoff from loadWorkflowSection() to render(). Livewire does
     * not persist private properties between requests, so this removes duplicate
     * initial Order hydration without creating stale state for later actions.
     */
    private ?FlowJob $preparedWorkflowJob = null;
    public ?int $selectedJobId = null;
    public ?int $selectedTaskId = null;
    public string $detailTab = 'overview';
    public array $expandedPhaseIds = [];
    public ?int $overviewPhaseId = null;
    // Only the opened task needs complete document/link metadata. This public
    // ID is never trusted as authorization; the action verifies task scope.
    public ?int $expandedResourceTaskId = null;
    public ?int $lastOverviewWorkflowPhaseId = null;
    public ?string $focusComment = null;

    public bool $showOrderWorkflowActionModal = false;
    public ?int $orderWorkflowActionTaskId = null;
    public string $orderWorkflowActionComment = '';
    public $orderWorkflowActionAttachment = null;
    public array $orderWorkflowActionRevisionComments = [];
    public array $orderWorkflowActionRevisionAttachments = [];
    public string $orderWorkflowActionStep = 'main';
    public array $orderWorkflowActionPayload = [];
    public array $orderWorkflowActionModalPreview = [];
    public bool $orderWorkflowEmailFallback = false;
    public string $orderWorkflowEmailFallbackMessage = '';
    public int $orderWorkflowEmailFallbackAttempts = 0;
    public array $orderWorkflowEmailResendFeedback = [];

    public bool $showOverviewTaskDocumentModal = false;
    public ?int $overviewTaskDocumentModalTaskId = null;
    public string $overviewTaskDocumentSource = 'upload';
    public array $overviewTaskDocumentUpload = [];
    public array $overviewTaskRevisionUpload = [];
    public array $overviewTaskStagedUploads = [];
    public array $overviewTaskStagedRevisionUploads = [];
    public array $overviewTaskRevisionDocumentIds = [];
    public ?int $overviewTaskExistingDocumentId = null;
    public string $overviewTaskDocumentNote = '';
    public ?int $overviewTaskLinkFormTaskId = null;
    public string $overviewTaskLinkUrl = '';
    public array $overviewTaskUploads = [];

    public bool $showAddOrderTaskForm = false;
    public string $newOrderTaskName = '';
    public string $newOrderTaskDescription = '';
    public ?int $newOrderTaskPhaseId = null;
    public ?int $newOrderTaskAssigneeId = null;
    public string $newOrderTaskDueDate = '';

    // Compatibility state used by shared task/resource concerns.
    public bool $taskEditMode = false;
    public string $taskStatus = 'Ready';
    public ?int $taskAssigneeId = null;
    public int $taskProgress = 0;
    public bool $taskAttention = false;
    public string $taskAttentionReason = '';
    public string $taskComment = '';
    public string $newChecklistItem = '';
    public string $taskActivityTab = 'all';
    public int $taskActivityPage = 1;
    public array $taskDocumentUploads = [];
    public ?int $taskExistingDocumentId = null;
    public bool $showTaskDocumentPicker = false;
    public string $jobActivityTab = 'all';
    public int $jobActivityPage = 1;

    // Compatibility state used by shared document concern. General Order
    // attachments are rendered by their own child, but task evidence still
    // uses these fields inside Workflow.
    public array $jobDocumentUploads = [];
    public $jobRequiredDocumentUpload = null;
    public ?int $jobDocumentTaskId = null;
    public ?int $existingDocumentId = null;
    public bool $showDocumentPicker = false;
    public ?int $lastJobDocumentUploadId = null;
    public ?int $lastJobDocumentTaskId = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->selectedJobId = $orderId;

        // With the optimized reader enabled, render the default stage's tasks
        // in the same first Order Details response as the parent header. The
        // previous viewport-triggered load remains the rollback path.
        if ((bool) config('flowtrack.order_three_level_loading_enabled', false)
            && (bool) config('flowtrack.workflow_display_query_enabled', false)) {
            $this->loadWorkflowSection('workflow', 'order', $orderId);
        }
    }

    #[On('order-hold-runtime-changed')]
    public function refreshOrderHoldRuntime(int $orderId, bool $held): void
    {
        if ($orderId !== $this->orderId) {
            return;
        }

        // This listener intentionally performs no write and does not duplicate
        // hold business logic. Receiving the targeted parent event is enough to
        // rerender this isolated child; render() re-reads activeHold from the DB
        // and rebuilds task permissions/actions from the existing services.
        // The boolean is accepted as part of the event contract so hold and
        // unhold share the exact same lightweight refresh path.
        unset($held);
    }

    public function loadWorkflowSection(string $section, ?string $contextType = null, ?int $contextId = null): void
    {
        if ($section !== 'workflow' || $contextType !== 'order' || (int) $contextId !== $this->orderId || $this->ready) {
            return;
        }

        // One model provides both the original phase and the auto-advance
        // input. A successful reconciliation refreshes it below; on a healthy
        // order there is no need for a second FlowJob SELECT.
        // Authorize before reconciliation, evidence repair or auto-advance.
        // A guessed Order ID must not trigger writes to an inaccessible Order.
        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);
        $runtimeJob = app(VisibleOrderQuery::class)->scoped($viewer, $this->orderId);
        $beforePhaseId = (int) ($runtimeJob->workflow_phase_id ?: 0);

        // Preserve historical self-healing, but keep it outside the critical
        // initial document request. The workflow is repaired before any task
        // action is exposed to the user.
        $workflowWasSynced = OrderBaselineRecorder::measure('workflow_sync',
            fn () => app(OrderWorkflowBindingService::class)->syncSingleActiveOrder($this->orderId), $this->orderId);
        if (! $workflowWasSynced) {
            OrderBaselineRecorder::measure('artwork_repair',
                fn () => app(OrderArtworkEvidenceService::class)->repair($this->orderId), $this->orderId);
        }

        if ($workflowWasSynced) {
            $runtimeJob->refresh();
        }
        $this->preparedWorkflowJob = OrderBaselineRecorder::measure('workflow_auto_advance',
            fn () => app(AutoAdvanceOrder::class)->handle($runtimeJob, auth()->user()), $this->orderId);
        if ((bool) config('flowtrack.workflow_read_snapshot_enabled', false)
            && (bool) config('flowtrack.workflow_read_snapshot_writes_enabled', false)) {
            // Binding/evidence repair and AutoAdvance may have committed a Task
            // mutation after $runtimeJob was first fetched. Keep the same
            // prepared Order and re-read only its durable source version.
            $this->preparedWorkflowJob->workflow_display_version = (int) \Illuminate\Support\Facades\DB::table('flow_jobs')
                ->where('id', $this->orderId)->value('workflow_display_version');
        }

        // AutoAdvanceOrder returns the already-authorized/readied Order used to
        // make the phase decision. Reuse its phase value instead of issuing a
        // second scalar FlowJob query immediately before render().
        $afterPhaseId = (int) ($this->preparedWorkflowJob->workflow_phase_id ?: 0);
        $this->overviewPhaseId = $afterPhaseId ?: $beforePhaseId ?: null;
        $this->lastOverviewWorkflowPhaseId = $this->overviewPhaseId;
        $this->ready = true;

        if ($workflowWasSynced || $beforePhaseId !== $afterPhaseId) {
            $this->dispatch('order-runtime-refreshed', orderId: $this->orderId);
        }
    }


    public function selectOverviewPhase(int $phaseId): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        if (! (bool) config('flowtrack.order_three_level_loading_enabled', false)
            || ! (bool) config('flowtrack.workflow_display_query_enabled', false)) {
            // Explicit rollback: keep the original phase-switch reader when
            // the three-level feature is disabled.
            $job = app(VisibleOrderQuery::class)->base($user, $this->orderId);
            app(VisibleOrderQuery::class)->loadOverviewShell($job, $user);
            $phase = $job->workflow?->phases?->firstWhere('id', $phaseId);
            abort_unless($phase, 404);
            abort_if((int) $phase->sequence > (int) ($job->phase?->sequence ?? 0), 422,
                'Complete the current stage before opening a future stage.');
            $this->overviewPhaseId = (int) $phase->id;
            $this->expandedResourceTaskId = null;
            return;
        }

        // Phase switches need only the authorized Order and phase sequences.
        // loadOverviewShell() would also fetch current-stage task documents and
        // links, unnecessarily repeating them before the selected-stage render.
        $job = app(VisibleOrderQuery::class)->scoped(
            $user, $this->orderId, ['phase:id,sequence'],
            ['id', 'workflow_id', 'workflow_phase_id', 'completed_at', 'status'],
        );
        $published = ! $job->completed_at
            && ! in_array((string) $job->status, \App\Services\JobService::INACTIVE_STATUSES, true)
            && app(\App\Services\OrderWorkflowSetupService::class)
                ->isActiveOrderWorkflow((int) $job->workflow_id);
        $phase = \App\Models\WorkflowPhase::query()
            ->whereKey($phaseId)
            ->where('is_active', true)
            ->when($published,
                fn ($query) => $query->where('workflow_template_id', (int) $job->workflow_id),
                fn ($query) => $query->where('workflow_id', (int) $job->workflow_id))
            ->first(['id', 'sequence']);
        abort_unless($phase, 404);
        abort_if((int) $phase->sequence > (int) ($job->phase?->sequence ?? 0), 422,
            'Complete the current stage before opening a future stage.');

        $this->overviewPhaseId = (int) $phase->id;
        $this->expandedResourceTaskId = null;
    }

    public function toggleTaskResources(int $taskId): void
    {
        abort_unless($this->ready && (bool) config('flowtrack.order_three_level_loading_enabled', false), 404);
        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);
        $order = app(VisibleOrderQuery::class)->base($viewer, $this->orderId);
        $phaseId = (int) ($this->overviewPhaseId ?: $order->workflow_phase_id);
        // Livewire public state can be changed by the client. Enforce the same
        // published/historical phase and future-stage restrictions as the
        // existing selectOverviewPhase() action before reading file metadata.
        $published = ! $order->completed_at
            && ! in_array((string) $order->status, \App\Services\JobService::INACTIVE_STATUSES, true)
            && app(\App\Services\OrderWorkflowSetupService::class)
                ->isActiveOrderWorkflow((int) $order->workflow_id);
        $phase = \App\Models\WorkflowPhase::query()
            ->whereKey($phaseId)
            ->when($published,
                fn ($query) => $query->where('workflow_template_id', (int) $order->workflow_id),
                fn ($query) => $query->where('workflow_id', (int) $order->workflow_id))
            ->firstOrFail();
        $currentSequence = (int) \App\Models\WorkflowPhase::query()
            ->whereKey((int) $order->workflow_phase_id)->value('sequence');
        abort_if((int) $phase->sequence > $currentSequence, 422,
            'Complete the current stage before opening a future stage.');

        $taskQuery = \App\Models\Task::query()
            ->where('flow_job_id', $this->orderId)
            ->where('workflow_phase_id', $phaseId)
            ->whereKey($taskId);
        app(AccessControlService::class)->applyTaskScope($taskQuery, $viewer);
        abort_unless($taskQuery->exists(), 404);

        $this->expandedResourceTaskId = $this->expandedResourceTaskId === $taskId ? null : $taskId;
    }

    // Task-detail navigation belongs to the parent route, not this isolated
    // section. A Livewire navigate redirect preserves the existing URL contract.
    public function openTask(int $id): void { $this->redirectRoute('jobs.index', ['task' => $id], navigate: true); }
    public function viewTask(int $id): void { $this->redirectRoute('jobs.index', ['task' => $id], navigate: true); }
    public function editTask(int $id): void { $this->redirectRoute('jobs.index', ['task' => $id], navigate: true); }

    #[On('flowtrack-order-changed')]
    public function refreshTargetedOrder(int $orderId): void
    {
        // The client-side order ID is only a refresh hint. Each render keeps
        // its existing server-side VisibleOrderQuery/access-control scope.
        if ($orderId !== $this->orderId || ! $this->ready) {
            $this->skipRender();
        }
    }

    #[On('flowtrack-notification')]
    #[On('flowtrack-refresh')]
    public function refreshRealtime(?string $reason = null): void
    {
        // Supplier actions ultimately update a Task, FlowJob or Document.
        // Ignore unrelated workspace settings and administration changes.
        if ($reason !== null && ! preg_match('/(?:^|\|)(?:Task|FlowJob|Document|FlowJobItem|FlowJobPhaseHistory):/', $reason)) {
            $this->skipRender();
        }
    }

    #[On('order-shipping-selection-updated')]
    public function refreshShippingSelection(int $orderId): void
    {
        // Planning & ownership is rendered by the parent component. When that
        // selector changes the canonical primary shipping choice, rerender this
        // isolated Workflow child so Task 5.1 reflects it immediately.
        if ($orderId !== $this->orderId) {
            return;
        }
    }

    /**
     * Track genuine phase changes before asking the scoped reader for task rows.
     * Do not switch an intentionally selected historical phase. If an editor is
     * open, defer following the new phase until its next safe render.
     */
    private function alignOverviewWithCurrentStage(int $currentPhaseId): void
    {
        $previousId = (int) ($this->lastOverviewWorkflowPhaseId ?: 0);
        if ($currentPhaseId > 0 && $previousId > 0
            && $currentPhaseId !== $previousId
            && (int) ($this->overviewPhaseId ?: 0) === $previousId) {
            if ($this->showOrderWorkflowActionModal
                || $this->showOverviewTaskDocumentModal
                || $this->showShipmentModal
                || $this->showShipmentDetailsModal
                || $this->shipmentInlineEditingId) {
                return;
            }

            $this->overviewPhaseId = $currentPhaseId;
            $this->expandedResourceTaskId = null;
        }

        $this->lastOverviewWorkflowPhaseId = $currentPhaseId ?: $this->lastOverviewWorkflowPhaseId;
    }

    public function render()
    {
        if (! $this->ready) {
            return view('livewire.jobs.order-workflow-section', [
                'job' => null,
                'taskStatuses' => collect(),
                'context' => [],
                'mentionUsers' => collect(),
                'overviewTaskDocumentModalTask' => null,
                'overviewTaskAvailableDocuments' => collect(),
                'overviewTaskArtworkRevision' => ['active' => false, 'documents' => collect(), 'retained_documents' => collect()],
            ]);
        }

        $user = auth()->user();
        $query = app(VisibleOrderQuery::class);
        $job = $this->preparedWorkflowJob;
        if (! $job || (int) $job->id !== $this->orderId) {
            $job = $query->base($user, $this->orderId);
        }
        $this->preparedWorkflowJob = null;

        $this->alignOverviewWithCurrentStage((int) ($job->workflow_phase_id ?: 0));

        if ((bool) config('flowtrack.workflow_display_query_enabled', false)) {
            app(\App\Queries\Orders\OrderWorkflowDisplayQuery::class)->preferDisplayPhase(
                $job,
                (int) ($this->overviewPhaseId ?: $job->workflow_phase_id),
                $this->showOverviewTaskDocumentModal || $this->showShipmentModal,
                $this->showOrderWorkflowActionModal ? (int) ($this->orderWorkflowActionTaskId ?: 0) : 0,
                (bool) config('flowtrack.order_three_level_loading_enabled', false)
                    ? (int) ($this->expandedResourceTaskId ?: 0) : 0,
            );
        }
        OrderBaselineRecorder::measure('workflow_load_relations',
            fn () => $query->loadOverviewWorkflow($job, $user), $this->orderId);

        $currentPhaseId = (int) ($job->workflow_phase_id ?: 0);
        $selectedPhase = $job->workflow?->phases?->firstWhere('id', (int) ($this->overviewPhaseId ?: 0));
        if (! $selectedPhase || (int) $selectedPhase->sequence > (int) ($job->phase?->sequence ?? 0)) {
            $this->overviewPhaseId = $currentPhaseId;
        }
        // A refresh or auto-advance can move the current phase while a task's
        // file list is open. Never let a prior phase keep resource hydration.
        if ($this->expandedResourceTaskId && ! $job->tasks->contains(fn ($task): bool =>
            (int) $task->id === $this->expandedResourceTaskId
            && (int) $task->workflow_phase_id === (int) $this->overviewPhaseId)) {
            $this->expandedResourceTaskId = null;
        }
        $master = app(MasterDataService::class);
        $taskStatuses = collect(BoardLaneResolver::taskStatuses($master->active('order_task_status')->pluck('name')));
        $shipmentUrgencies = $master->active('shipment_urgency');
        $shipmentMethods = $master->active('shipment_method');
        $couriers = $master->active('courier');

        $context = OrderBaselineRecorder::measure('workflow_detail_context',
            fn () => app(OrderDetailViewService::class)->build(
                $job,
                $user,
                $shipmentUrgencies,
                $couriers,
                (bool) config('flowtrack.workflow_display_query_enabled', false)
                    ? ($this->showOrderWorkflowActionModal ? (int) $this->orderWorkflowActionTaskId : 0)
                    : null,
            ), $this->orderId);
        // Supplier assignment is now inherited from Task Pack Setup; no row-level selector/query.
        $job->tasks->loadMissing('supplierAssignment');
        $context['shipmentMethods'] = $shipmentMethods;
        $context['shipmentUrgencies'] = $shipmentUrgencies;
        $context['shipmentCouriers'] = $couriers;

        // One compact, permission-scoped read supplies counters and the first
        // assignee for every other stage. Task cards and their full relations
        // are loaded only for the selected stage; no per-card SQL is issued.
        $scopedPhaseId = (bool) config('flowtrack.order_three_level_loading_enabled', false)
            && (bool) config('flowtrack.workflow_display_query_enabled', false)
            ? app(\App\Queries\Orders\OrderWorkflowDisplayQuery::class)
                ->selectedTaskPhaseId($job)
            : null;
        $context['phaseSummaries'] = $scopedPhaseId !== null
            ? app(\App\Queries\Orders\OrderPhaseSummaryQuery::class)
                ->forAuthorizedOrder($job, $user, $job->workflow?->phases ?? collect(), $scopedPhaseId)
            : [];

        $locationMaster = app(LocationMasterDataService::class);
        $shipmentLocationEditorOpen = filled($this->shipmentInlineEditingId);
        $shipmentCountryOptions = $shipmentLocationEditorOpen ? $locationMaster->countries() : collect();
        $shipmentCountry = trim((string) ($this->showShipmentModal
            ? ($this->shipmentForm['country'] ?? '')
            : ($this->shipmentInlineForm['country'] ?? '')));
        $shipmentStateOptions = $shipmentLocationEditorOpen && $shipmentCountry !== ''
            ? $locationMaster->statesForCountry($shipmentCountry)
            : collect();
        $context['shipmentCountries'] = $shipmentCountryOptions
            ->map(fn (MasterRecord $country) => [
                'id' => (string) $country->name,
                'label' => (string) $country->name,
                'meta' => trim((string) $country->code),
            ])->values()->all();
        $context['shipmentStates'] = $shipmentStateOptions
            ->map(fn (MasterRecord $state) => [
                'id' => (string) $state->name,
                'label' => (string) $state->name,
                'meta' => trim((string) $state->code),
            ])->values()->all();

        // Add/Edit Shipment now uses the same compact address controls as Create
        // Order. Load the searchable phone codes and saved client addresses only
        // while that modal is open so normal workflow renders stay lightweight.
        $context['shipmentPhoneCountryCodes'] = $this->showShipmentModal
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
        $context['shipmentSavedAddresses'] = $this->showShipmentModal && $job->client_id
            ? ClientShippingAddress::query()
                ->where('client_id', (int) $job->client_id)
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
        if ((bool) config('flowtrack.order_three_level_loading_enabled', false)) {
            // Legacy/historical and evidence-sensitive phases retain their
            // original inline files. Do not add counting queries to those paths.
            $deferred = (bool) config('flowtrack.workflow_display_query_enabled', false)
                && app(\App\Queries\Orders\OrderWorkflowDisplayQuery::class)
                    ->resourceDetailsDeferred($job);
            $context['taskResourceDetailsDeferred'] = $deferred;
            if ($deferred) {
                // Two grouped queries for the selected phase, never N+1.
                // File names and uploader data remain absent until a task opens.
                $selectedTaskIds = $job->tasks
                    ->where('workflow_phase_id', (int) $this->overviewPhaseId)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)->all();
                $context['taskLinkCounts'] = empty($selectedTaskIds) ? []
                    : \App\Models\TaskLink::query()
                        ->whereIn('task_id', $selectedTaskIds)
                        ->selectRaw('task_id, COUNT(*) AS link_count')
                        ->groupBy('task_id')
                        ->pluck('link_count', 'task_id')
                        ->all();
                $context['taskAttachmentCounts'] = empty($selectedTaskIds) ? []
                    : \App\Models\Document::query()
                        ->where('flow_job_id', $this->orderId)
                        ->whereIn('task_id', $selectedTaskIds)
                        ->when(! (bool) config('flowtrack.order_tracking_enabled', false),
                            fn ($query) => $query->where(fn ($scope) => $scope
                                ->whereNull('category')
                                ->orWhere('category', '!=', \App\Models\Document::CATEGORY_ARTWORK_TRACKING_PDF)))
                        ->selectRaw('task_id, COUNT(*) AS attachment_count')
                        ->groupBy('task_id')
                        ->pluck('attachment_count', 'task_id')
                        ->all();
                $context['expandedResourceTaskId'] = $this->expandedResourceTaskId;
            }
        }
        $context['workflowEmailResendFeedback'] = $this->orderWorkflowEmailResendFeedback;

        $overviewTaskDocumentModalTask = null;
        $overviewTaskAvailableDocuments = collect();
        $overviewTaskArtworkRevision = ['active' => false, 'documents' => collect(), 'retained_documents' => collect()];

        if ($this->showOverviewTaskDocumentModal && $this->overviewTaskDocumentModalTaskId) {
            $overviewTaskDocumentModalTask = $job->tasks->firstWhere('id', (int) $this->overviewTaskDocumentModalTaskId);
            if ($overviewTaskDocumentModalTask
                && app(\App\Services\OrderWorkflowActionService::class)->automationKey($overviewTaskDocumentModalTask) === 'ART_PREPARE_UPLOAD') {
                $overviewTaskArtworkRevision = app(DocumentService::class)->pendingArtworkRevision($overviewTaskDocumentModalTask);
            }
            if ($overviewTaskDocumentModalTask && $this->overviewTaskDocumentSource === 'existing') {
                $overviewTaskAvailableDocuments = app(DocumentService::class)
                    ->query($user, ['client' => $job->client_id])
                    ->with(['job:id,job_number', 'task:id,title'])
                    ->latest('id')
                    ->limit(60)
                    ->get();
            }
        }

        $mentionUsers = ($this->showOrderWorkflowActionModal || $this->showOverviewTaskDocumentModal)
            ? app(MentionService::class)->optionsForJob($job, $user)
            : collect();

        return view('livewire.jobs.order-workflow-section', compact(
            'job',
            'taskStatuses',
            'context',
            'mentionUsers',
            'overviewTaskDocumentModalTask',
            'overviewTaskAvailableDocuments',
            'overviewTaskArtworkRevision',
        ));
    }

    private function userOptions(User $user)
    {
        $isCreator = FlowJob::query()->whereKey($this->orderId)->where('created_by', $user->id)->exists();
        $canAssign = $isCreator || $user->canModule('tasks', 'assign') || $user->canModule('jobs', 'assign');

        return $canAssign
            ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'profile_image_path'])
            : collect([(object) ['id' => $user->id, 'name' => $user->name, 'profile_image_path' => $user->profile_image_path]]);
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

    private function setDefaultDocumentTask(?FlowJob $job = null): void
    {
        // General Order attachments moved to OrderAttachmentsSection. This
        // compatibility hook is retained only for shared concern methods that
        // are not used by the workflow UI.
    }
}
