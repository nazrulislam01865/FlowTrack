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
 * Runtime reconciliation is deferred from the initial Order GET until this
 * section is actually requested.
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

        $beforePhaseId = (int) (FlowJob::query()->whereKey($this->orderId)->value('workflow_phase_id') ?: 0);

        // Preserve historical self-healing, but keep it outside the critical
        // initial document request. The workflow is repaired before any task
        // action is exposed to the user.
        $workflowWasSynced = app(OrderWorkflowBindingService::class)->syncSingleActiveOrder($this->orderId);
        if (! $workflowWasSynced) {
            app(OrderArtworkEvidenceService::class)->repair($this->orderId);
        }

        $runtimeJob = FlowJob::query()->findOrFail($this->orderId);
        $this->preparedWorkflowJob = app(AutoAdvanceOrder::class)->handle($runtimeJob, auth()->user());

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
        $job = app(VisibleOrderQuery::class)->base($user, $this->orderId);
        app(VisibleOrderQuery::class)->loadOverviewShell($job, $user);

        $phase = $job->workflow?->phases?->firstWhere('id', $phaseId);
        abort_unless($phase, 404);
        abort_if((int) $phase->sequence > (int) ($job->phase?->sequence ?? 0), 422, 'Complete the current stage before opening a future stage.');

        $this->overviewPhaseId = (int) $phase->id;
    }

    // Task-detail navigation belongs to the parent route, not this isolated
    // section. A Livewire navigate redirect preserves the existing URL contract.
    public function openTask(int $id): void { $this->redirectRoute('jobs.index', ['task' => $id], navigate: true); }
    public function viewTask(int $id): void { $this->redirectRoute('jobs.index', ['task' => $id], navigate: true); }
    public function editTask(int $id): void { $this->redirectRoute('jobs.index', ['task' => $id], navigate: true); }

    #[On('flowtrack-notification')]
    #[On('flowtrack-refresh')]
    public function refreshRealtime(?string $reason = null): void
    {
        // Supplier actions ultimately update a Task, FlowJob or Document.
        // Ignore unrelated workspace settings and administration changes.
        if ($reason !== null && ! preg_match('/^(Task|FlowJob|Document|FlowJobItem|FlowJobPhaseHistory):/', $reason)) {
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
        $query->loadOverviewWorkflow($job, $user);

        $currentPhaseId = (int) ($job->workflow_phase_id ?: 0);
        $selectedPhase = $job->workflow?->phases?->firstWhere('id', (int) ($this->overviewPhaseId ?: 0));
        if (! $selectedPhase || (int) $selectedPhase->sequence > (int) ($job->phase?->sequence ?? 0)) {
            $this->overviewPhaseId = $currentPhaseId;
        }
        $this->lastOverviewWorkflowPhaseId = $currentPhaseId ?: $this->lastOverviewWorkflowPhaseId;

        $master = app(MasterDataService::class);
        $taskStatuses = collect(BoardLaneResolver::taskStatuses($master->active('order_task_status')->pluck('name')));
        $shipmentUrgencies = $master->active('shipment_urgency');
        $shipmentMethods = $master->active('shipment_method');
        $couriers = $master->active('courier');

        $context = app(OrderDetailViewService::class)->build($job, $user, $shipmentUrgencies, $couriers);
        // Supplier assignment is now inherited from Task Pack Setup; no row-level selector/query.
        $job->tasks->loadMissing('supplierAssignment');
        $context['shipmentMethods'] = $shipmentMethods;
        $context['shipmentUrgencies'] = $shipmentUrgencies;
        $context['shipmentCouriers'] = $couriers;

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
