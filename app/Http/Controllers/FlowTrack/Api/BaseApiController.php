<?php

namespace App\Http\Controllers\FlowTrack\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreClientRequest;
use App\Http\Requests\FlowTrack\StoreJobRequest;
use App\Http\Requests\FlowTrack\StoreTaskRequest;
use App\Http\Requests\FlowTrack\StoreDocumentRequest;
use App\Http\Requests\FlowTrack\UpdateTaskRequest;
use App\Http\Requests\FlowTrack\UpdateTaskDueDateRequest;
use App\Http\Requests\FlowTrack\UpdateJobRequest;
use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateClientAction;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\Actions\CreateTaskAction;
use App\Modules\OrderFlow\Application\Actions\DeleteClientAction;
use App\Modules\OrderFlow\Application\Actions\DeleteDocumentAction;
use App\Modules\OrderFlow\Application\Actions\DeleteJobAction;
use App\Modules\OrderFlow\Application\Actions\MoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\RemoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\RemoveTaskFromJobAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskAction;
use App\Modules\OrderFlow\Application\Actions\UpdateClientAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskDueDateAction;
use App\Modules\OrderFlow\Application\Actions\UpdateJobAction;
use App\Modules\OrderFlow\Application\Actions\UploadDocumentAction;
use App\Modules\OrderFlow\Application\Actions\UploadJobAttachmentAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Application\DTOs\UpdateJobData;
use App\Modules\OrderFlow\Application\Presenters\CurrentUserPresenter;
use App\Modules\OrderFlow\Application\Support\FlowTrackBootstrapCache;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService;
use App\Modules\OrderFlow\Business\Services\OperationalMetricsService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use App\Modules\OrderFlow\Business\Services\TaskPackActivator;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowActivity;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Modules\OrderFlow\Data\Repositories\JobRepository;
use App\Modules\OrderFlow\Data\Repositories\TaskRepository;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;


abstract class BaseApiController extends Controller
{
    /** @var array<int, array<int, array<string, mixed>>> */
    protected array $jobActivityCache = [];

    public function __construct(protected readonly JobDocumentRequirementService $documentRequirements) {}

    protected function loadJob(FlowJob $job): FlowJob
    {
        return $job->fresh([
            'client:id,name,code', 'workflow.phases', 'currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory', 'startedPhase',
            'product:id,name,code', 'productCategory:id,name,code', 'items.product:id,name,code', 'items.productCategory:id,name,code', 'currency:id,name,code',
            'priority:id,name,code,metadata', 'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
            'members.user:id,name,initials', 'excludedPhases.phase:id,name,short_name,sequence', 'phaseHistory.phase:id,name,short_name,sequence', 'phaseHistory.changedBy:id,name,initials', 'phaseHistory.phaseOwner:id,name,initials',
            'transitionEvents.actor:id,name,initials', 'transitionEvents.phase:id,name,short_name', 'transitionEvents.task:id,code,title',
        ]);
    }

    protected function loadJobTasks(FlowJob $job, User $user): Collection
    {
        return app(FlowAuthorizationService::class)
            ->applyTaskVisibility(FlowTask::query(), $user)
            ->where('flow_job_id', $job->id)
            ->with([
                'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id,health,health_reason,delivery_date,status,attention_required', 'job.client:id,name',
                'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                'job.documents.category:id,name', 'job.documents.uploader:id,name,initials', 'job.documents.task:id,code,title', 'job.documents.versions.uploader:id,name,initials',
                'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
                'packItem:id,task_pack_id,title,document_category_id,is_required', 'packItem.documentCategory:id,name',
                'documentCategory:id,name',
                'assignee:id,name,initials', 'setupAssignee:id,name,initials', 'setupAssignee.workspaceMemberships.department', 'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $job->workspace_id)->with('department:id,name'),
                'status:id,name,code,metadata', 'priority:id,name,code,metadata', 'creator:id,name,initials',
                'dependency:id,code,title,task_status_id,completed_at', 'dependency.status:id,name,code,metadata',
                'dependents:id,dependency_id,code,title,task_status_id,completed_at', 'dependents.status:id,name,code,metadata',
                'checklistItems', 'comments.user:id,name,initials',
                'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
            ])
            ->orderBy(
                WorkflowPhase::query()
                    ->select('sequence')
                    ->whereColumn('workflow_phases.id', 'flow_tasks.workflow_phase_id')
                    ->limit(1)
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    protected function loadTask(FlowTask $task, User $user): FlowTask
    {
        $workspaceId = (int) $user->currentWorkspaceId();

        return $task->fresh([
            'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id,health,health_reason,delivery_date,status,attention_required',
            'job.client:id,name', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials',
            'job.members.user:id,name,initials', 'job.documents.category:id,name',
            'job.documents.uploader:id,name,initials', 'job.documents.task:id,code,title', 'job.documents.versions.uploader:id,name,initials',
            'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
            'packItem:id,task_pack_id,title,document_category_id,is_required', 'packItem.documentCategory:id,name',
            'documentCategory:id,name', 'creator:id,name,initials', 'assignee:id,name,initials',
            'setupAssignee:id,name,initials',
            'setupAssignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $workspaceId)->with('department:id,name'),
            'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $workspaceId)->with('department:id,name'),
            'status:id,name,code,metadata', 'priority:id,name,code,metadata', 'checklistItems', 'comments.user:id,name,initials',
            'dependency:id,code,title,task_status_id,completed_at', 'dependency.status:id,name,code,metadata',
            'dependents:id,dependency_id,code,title,task_status_id,completed_at', 'dependents.status:id,name,code,metadata',
            'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
        ]);
    }

    protected function mapJobList(FlowJob $job, bool $canViewFinancials = false): array
    {
        $productItems = $job->items->map(fn ($item): array => [
            'id' => $item->id,
            'productId' => $item->product_id,
            'product' => $item->product?->name ?? 'Deleted product',
            'productCode' => $item->product?->code ?? '',
            'categoryId' => $item->product_category_id,
            'category' => $item->productCategory?->name ?? '',
            'qty' => (int) $item->quantity,
        ])->values();

        if ($productItems->isEmpty() && $job->product_id) {
            $productItems->push([
                'id' => null,
                'productId' => $job->product_id,
                'product' => $job->product?->name ?? 'Deleted product',
                'productCode' => $job->product?->code ?? '',
                'categoryId' => $job->product_category_id,
                'category' => $job->productCategory?->name ?? '',
                'qty' => (int) $job->quantity,
            ]);
        }

        $productSummary = match (true) {
            $productItems->isEmpty() => 'Unspecified product',
            $productItems->count() === 1 => $productItems->first()['product'],
            default => $productItems->first()['product'].' + '.($productItems->count() - 1).' more',
        };
        $categoryNames = $productItems->pluck('category')->filter()->unique()->values();

        return [
            'dbId' => $job->id,
            'id' => $job->code,
            'order' => $job->order_code ?? '',
            'title' => $job->title,
            'requestDescription' => $job->request_description ?? '',
            'client' => $job->client_id,
            'clientName' => $job->client?->name ?? 'Unknown client',
            'product' => $productSummary,
            'productId' => $productItems->first()['productId'] ?? $job->product_id,
            'products' => $productItems->all(),
            'productCount' => $productItems->count(),
            'category' => $categoryNames->count() > 1 ? 'Multiple categories' : ($categoryNames->first() ?? ''),
            'categoryId' => $productItems->first()['categoryId'] ?? $job->product_category_id,
            'qty' => (int) $job->quantity,
            'price' => $canViewFinancials ? (float) $job->quoted_amount : null,
            'currency' => $job->currency?->code ?? 'USD',
            'currencyId' => $job->currency_id,
            'phase' => max(0, ((int) ($job->currentPhase?->sequence ?? 1)) - 1),
            'phaseId' => $job->current_phase_id,
            'status' => $this->label($job->status),
            'active' => ! in_array(strtolower((string) $job->status), ['inactive', 'deactivated', 'archived'], true),
            'createdAt' => $job->created_at?->toIso8601String(),
            'updatedAt' => $job->updated_at?->toIso8601String(),
            'completedAt' => $job->completed_at?->toIso8601String()
                ?? (strtolower((string) $job->status) === 'completed' ? $job->updated_at?->toIso8601String() : null),
            'health' => $this->label($job->health),
            'healthReason' => $job->health_reason ?? '',
            'progress' => (int) $job->progress,
            'owner' => $job->owner?->name ?? 'Unassigned',
            'ownerId' => $job->owner_id,
            'coordinator' => $job->coordinator?->name ?? 'Unassigned',
            'coordinatorId' => $job->coordinator_id,
            'createdById' => $job->created_by,
            'createdBy' => $job->creator?->name ?? 'Unknown',
            'memberIds' => $job->relationLoaded('members')
                ? $job->members->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
                : [],
            'members' => $job->relationLoaded('members')
                ? $job->members->map(fn (FlowJobMember $member): array => [
                    'id' => (int) $member->user_id,
                    'name' => $member->user?->name ?? 'Unknown',
                    'initials' => $member->user?->initials ?: $this->initials($member->user?->name ?? 'Unknown'),
                    'canManageTasks' => (bool) $member->can_manage_tasks,
                ])->values()->all()
                : [],
            'canDeletePhases' => false,
            'excludedPhaseIds' => [],
            'delivery' => $job->delivery_date?->toDateString() ?? '',
            'priority' => $job->priority?->name ?? 'Medium',
            'priorityId' => $job->priority_id,
            'priorityColor' => data_get($job->priority?->metadata, 'color'),
            'next' => $job->next_action ?? 'Review current phase',
            'milestone' => $job->milestone ?? '',
            'attention' => (bool) $job->attention_required,
            'phaseReadiness' => 'pending',
            'phaseReadinessLabel' => 'Pending',
            'phaseReadinessDetails' => [],
            'phaseHistory' => [],
            'documentRequirements' => [],
            'workflow' => $job->workflow_template_id,
            'workflowName' => $job->workflow?->name ?? 'Workflow',
            'createdFromPhase' => max(0, ((int) ($job->startedPhase?->sequence ?? 1)) - 1),
            'skippedPhases' => [],
            'startHandling' => $this->label($job->start_handling),
            'startReason' => $job->start_reason ?? '',
            'activity' => [],
            '_detailLoaded' => false,
        ];
    }

    protected function mapTaskList(FlowTask $task): array
    {
        $effectiveAssignee = $task->assignee
            ?: ((! $task->assignee_overridden_at && $task->setup_assignee_id) ? $task->setupAssignee : null);
        $effectiveAssigneeId = $task->assignee_id
            ?: ((! $task->assignee_overridden_at && $task->setup_assignee_id) ? $task->setup_assignee_id : null);
        $membership = $effectiveAssignee?->workspaceMemberships?->first();
        $actor = request()->user();
        $authorization = app(FlowAuthorizationService::class);
        $documentCategory = $task->documentCategory ?: $task->packItem?->documentCategory;
        $documentSource = $task->document_requirement_source
            ?: ($task->packItem?->document_category_id ? 'task_pack_item' : null);
        // Task document evidence is driven only by Task Pack Setup. A title such
        // as "upload artwork" must never invent an attachment/document slot.
        $isConfiguredDocumentTask = (bool) ($documentCategory && $documentSource === 'task_pack_item');
        $isDocumentTask = $isConfiguredDocumentTask;

        return [
            'dbId' => $task->id,
            'id' => $task->code,
            'job' => $task->job?->code,
            'jobDbId' => $task->flow_job_id,
            'jobTitle' => $task->job?->title,
            'jobContext' => $task->job ? [
                'dbId' => (int) $task->job->id,
                'id' => $task->job->code,
                'title' => $task->job->title,
                'client' => $task->job->client_id,
                'clientName' => $task->job->client?->name ?? 'Unknown client',
                'health' => $this->label($task->job->health ?? 'on_track'),
                'healthReason' => $task->job->health_reason ?? '',
                'delivery' => $task->job->delivery_date?->toDateString() ?? '',
                'progress' => (int) ($task->job->progress ?? 0),
                'status' => $this->label($task->job->status ?? 'active'),
                'attention' => (bool) ($task->job->attention_required ?? false),
            ] : null,
            'title' => $task->title,
            'description' => $task->description ?? '',
            'startDate' => $task->start_date?->toDateString() ?? $task->created_at?->toDateString() ?? '',
            'status' => $task->status?->name ?? 'Not Started',
            'statusId' => $task->task_status_id,
            'statusCode' => $task->status?->code,
            'statusColor' => data_get($task->status?->metadata, 'color'),
            'createdAt' => $task->created_at?->toIso8601String(),
            'createdBy' => $task->creator?->name ?? 'System',
            'createdByInitials' => $task->creator?->initials ?: $this->initials($task->creator?->name ?? 'System'),
            'updatedAt' => $task->updated_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'completedBy' => $task->completed_at ? ($effectiveAssignee?->name ?? 'System') : null,
            'completedByInitials' => $task->completed_at ? $this->initials($effectiveAssignee?->name ?? 'System') : null,
            'assignee' => $effectiveAssignee?->name ?? 'Unassigned',
            'assigneeId' => $effectiveAssigneeId ? (int) $effectiveAssigneeId : null,
            'setupAssigneeId' => $task->setup_assignee_id ? (int) $task->setup_assignee_id : null,
            'setupAssignee' => $task->setupAssignee?->name,
            'assigneeOverridden' => (bool) $task->assignee_overridden_at,
            'canUpdate' => $actor ? $authorization->canUpdateTask($actor, $task) : false,
            'canAssign' => $actor ? $authorization->canAssignExistingTask($actor, $task) : false,
            'canDelete' => $actor ? $authorization->canDeleteTask($actor, $task) : false,
            'canManageChecklist' => $actor ? $authorization->canManageTaskChecklist($actor, $task) : false,
            'canChangeDueDate' => $actor ? $authorization->canChangeTaskDueDate($actor, $task) : false,
            'dept' => $membership?->department?->name ?? 'Unassigned',
            'due' => $task->due_date?->toDateString() ?? '',
            'priority' => $task->priority?->name ?? 'Medium',
            'priorityId' => $task->priority_id,
            'priorityColor' => data_get($task->priority?->metadata, 'color'),
            'progress' => (int) $task->progress,
            'phase' => $task->phase?->name ?? $task->phase?->short_name ?? 'General',
            'phaseName' => $task->phase?->name ?? 'General',
            'phaseShortName' => $task->phase?->short_name ?? '',
            'taskPackName' => $task->phase?->taskPack?->name ?? null,
            'phaseIndex' => max(0, ((int) ($task->phase?->sequence ?? 1)) - 1),
            'phaseId' => $task->workflow_phase_id,
            'sortOrder' => (int) $task->sort_order,
            'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
            'taskPackItemTitle' => $task->packItem?->title,
            'attention' => (bool) $task->attention_required,
            'blockerReason' => $task->blocker_reason ?? '',
            'isDocumentTask' => $isDocumentTask,
            'documentRequirement' => $isConfiguredDocumentTask ? [
                'source' => $documentSource,
                'phaseId' => (int) $task->workflow_phase_id,
                'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
                'taskId' => (int) $task->id,
                'categoryId' => (int) $documentCategory->id,
                'categoryName' => $documentCategory->name,
            ] : null,
            'documents' => [],
            'canUploadDocument' => false,
            'canUploadAttachment' => false,
            'mentionableUsers' => [],
            'history' => [],
            'checklist' => [],
            'comments' => [],
            '_detailLoaded' => false,
        ];
    }

    protected function mapClient(Client $client, bool $canViewFinancials = false, bool $canViewContacts = false): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'code' => $client->code,
            'country' => $client->country ?? '',
            'contact' => $canViewContacts ? ($client->contact_name ?? '') : '',
            'contact_name' => $canViewContacts ? ($client->contact_name ?? '') : '',
            'email' => $canViewContacts ? ($client->email ?? '') : '',
            'phone' => $canViewContacts ? ($client->phone ?? '') : '',
            'manager' => $client->accountManager?->name ?? 'Unassigned',
            'manager_id' => $client->account_manager_id,
            'account_manager_id' => $client->account_manager_id,
            'preferred_language' => $client->preferred_language ?? 'en',
            'jobs' => (int) ($client->active_jobs_count ?? 0),
            'active_jobs_count' => (int) ($client->active_jobs_count ?? 0),
            'attention_jobs_count' => (int) ($client->attention_jobs_count ?? 0),
            'outstanding' => $canViewFinancials ? (float) $client->outstanding_balance : null,
            'outstanding_balance' => $canViewFinancials ? (float) $client->outstanding_balance : null,
            'notes' => $canViewContacts ? ($client->notes ?? '') : '',
        ];
    }

    protected function mapJob(FlowJob $job, bool $canViewFinancials = false): array
    {
        $skipped = $job->phaseHistory
            ->where('status', 'skipped')
            ->map(fn ($history) => max(0, ((int) $history->phase?->sequence ?: 1) - 1))
            ->values()
            ->all();

        $productItems = $job->items->map(fn ($item): array => [
            'id' => $item->id,
            'productId' => $item->product_id,
            'product' => $item->product?->name ?? 'Deleted product',
            'productCode' => $item->product?->code ?? '',
            'categoryId' => $item->product_category_id,
            'category' => $item->productCategory?->name ?? '',
            'qty' => (int) $item->quantity,
        ])->values();

        if ($productItems->isEmpty() && $job->product_id) {
            $productItems->push([
                'id' => null,
                'productId' => $job->product_id,
                'product' => $job->product?->name ?? 'Deleted product',
                'productCode' => $job->product?->code ?? '',
                'categoryId' => $job->product_category_id,
                'category' => $job->productCategory?->name ?? '',
                'qty' => (int) $job->quantity,
            ]);
        }

        $productSummary = match (true) {
            $productItems->isEmpty() => 'Unspecified product',
            $productItems->count() === 1 => $productItems->first()['product'],
            default => $productItems->first()['product'].' + '.($productItems->count() - 1).' more',
        };
        $categoryNames = $productItems->pluck('category')->filter()->unique()->values();

        return [
            'dbId' => $job->id,
            'id' => $job->code,
            'order' => $job->order_code ?? '',
            'title' => $job->title,
            'requestDescription' => $job->request_description ?? '',
            'client' => $job->client_id,
            'clientName' => $job->client?->name ?? 'Unknown client',
            'product' => $productSummary,
            'productId' => $productItems->first()['productId'] ?? $job->product_id,
            'products' => $productItems->all(),
            'productCount' => $productItems->count(),
            'category' => $categoryNames->count() > 1 ? 'Multiple categories' : ($categoryNames->first() ?? ''),
            'categoryId' => $productItems->first()['categoryId'] ?? $job->product_category_id,
            'qty' => (int) $job->quantity,
            'price' => $canViewFinancials ? (float) $job->quoted_amount : null,
            'currency' => $job->currency?->code ?? 'USD',
            'currencyId' => $job->currency_id,
            'phase' => max(0, ((int) ($job->currentPhase?->sequence ?? 1)) - 1),
            'phaseId' => $job->current_phase_id,
            'status' => $this->label($job->status),
            'active' => ! in_array(strtolower((string) $job->status), ['inactive', 'deactivated', 'archived'], true),
            'createdAt' => $job->created_at?->toIso8601String(),
            'updatedAt' => $job->updated_at?->toIso8601String(),
            'completedAt' => $job->completed_at?->toIso8601String()
                ?? (strtolower((string) $job->status) === 'completed' ? $job->updated_at?->toIso8601String() : null),
            'health' => $this->label($job->health),
            'healthReason' => $job->health_reason ?? '',
            'progress' => (int) $job->progress,
            'owner' => $job->owner?->name ?? 'Unassigned',
            'ownerId' => $job->owner_id,
            'coordinator' => $job->coordinator?->name ?? 'Unassigned',
            'coordinatorId' => $job->coordinator_id,
            'createdById' => $job->created_by,
            'createdBy' => $job->creator?->name ?? 'Unknown',
            'memberIds' => $job->relationLoaded('members')
                ? $job->members->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
                : [],
            'members' => $job->relationLoaded('members')
                ? $job->members->map(fn (FlowJobMember $member): array => [
                    'id' => (int) $member->user_id,
                    'name' => $member->user?->name ?? 'Unknown',
                    'initials' => $member->user?->initials ?: $this->initials($member->user?->name ?? 'Unknown'),
                    'canManageTasks' => (bool) $member->can_manage_tasks,
                ])->values()->all()
                : [],
            // Job workflow phases are structural and are never removable from
            // the Job Board. Workflow Setup remains the only configuration area.
            'canDeletePhases' => false,
            'excludedPhaseIds' => $job->excludedPhases
                ->pluck('workflow_phase_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'delivery' => $job->delivery_date?->toDateString() ?? '',
            'priority' => $job->priority?->name ?? 'Medium',
            'priorityId' => $job->priority_id,
            'priorityColor' => data_get($job->priority?->metadata, 'color'),
            'next' => $job->next_action ?? 'Review current phase',
            'milestone' => $job->milestone ?? '',
            'attention' => (bool) $job->attention_required,
            'phaseReadiness' => optional($job->phaseHistory->firstWhere('workflow_phase_id', $job->current_phase_id))->readiness_status ?? 'pending',
            'phaseReadinessLabel' => $this->label(optional($job->phaseHistory->firstWhere('workflow_phase_id', $job->current_phase_id))->readiness_status ?? 'pending'),
            'phaseReadinessDetails' => optional($job->phaseHistory->firstWhere('workflow_phase_id', $job->current_phase_id))->readiness_details ?? [],
            'phaseHistory' => $job->phaseHistory
                ->map(fn ($history): array => [
                    'id' => $history->id,
                    'phaseId' => (int) $history->workflow_phase_id,
                    'phaseIndex' => max(0, ((int) ($history->phase?->sequence ?? 1)) - 1),
                    'phaseName' => $history->phase?->name ?? '',
                    'phaseShortName' => $history->phase?->short_name ?? '',
                    'status' => $history->status,
                    'statusLabel' => $this->label($history->status),
                    'readinessStatus' => $history->readiness_status,
                    'readinessLabel' => $this->label($history->readiness_status),
                    'enteredAt' => $history->entered_at?->toIso8601String(),
                    'completedAt' => $history->completed_at?->toIso8601String(),
                    'changedBy' => $history->changedBy?->name ?? 'System',
                    'changedByInitials' => $history->changedBy?->initials ?: $this->initials($history->changedBy?->name ?? 'System'),
                    'phaseOwnerId' => $history->phase_owner_id ? (int) $history->phase_owner_id : null,
                    'phaseOwner' => $history->phaseOwner?->name,
                    'phaseOwnerInitials' => $history->phaseOwner?->initials ?: ($history->phaseOwner ? $this->initials($history->phaseOwner->name) : null),
                    'targetDate' => $history->target_date?->toDateString(),
                    'healthOverride' => $history->health_override,
                    'automationMode' => $history->automation_mode,
                    'notes' => $history->notes ?? '',
                    'readinessDetails' => $history->readiness_details ?? [],
                ])
                ->sortBy('phaseIndex')
                ->values()
                ->all(),
            'documentRequirements' => $this->documentRequirements->allRequirements($job)->values()->all(),
            'workflow' => $job->workflow_template_id,
            'workflowName' => $job->workflow?->name ?? 'Workflow',
            'createdFromPhase' => max(0, ((int) ($job->startedPhase?->sequence ?? 1)) - 1),
            'skippedPhases' => $skipped,
            'startHandling' => $this->label($job->start_handling),
            'startReason' => $job->start_reason ?? '',
            'activity' => $this->jobActivity($job),
            '_detailLoaded' => true,
        ];
    }

    protected function mapTask(FlowTask $task): array
    {
        $task->loadMissing([
            'packItem.documentCategory', 'documentCategory', 'setupAssignee.workspaceMemberships.department',
            'creator:id,name,initials', 'dependency.status:id,name,code,metadata', 'dependents.status:id,name,code,metadata',
        ]);
        $effectiveAssignee = $task->assignee ?: ((! $task->assignee_overridden_at && $task->setup_assignee_id) ? $task->setupAssignee : null);
        $effectiveAssigneeId = $task->assignee_id ?: ((! $task->assignee_overridden_at && $task->setup_assignee_id) ? $task->setup_assignee_id : null);
        $membership = $effectiveAssignee?->workspaceMemberships?->first() ?: $task->assignee?->workspaceMemberships?->first();
        $actor = request()->user();
        $authorization = app(FlowAuthorizationService::class);
        $documentCategory = $task->documentCategory ?: $task->packItem?->documentCategory;
        $documentSource = $task->document_requirement_source
            ?: ($task->packItem?->document_category_id ? 'task_pack_item' : null);
        $isConfiguredDocumentTask = (bool) ($documentCategory && $documentSource === 'task_pack_item');
        $isAttachmentTask = $isConfiguredDocumentTask;
        $documentRequirementSatisfied = ! $isConfiguredDocumentTask
            || $this->documentRequirements->isTaskRequirementSatisfiedReadOnly($task);

        // Every Task may hold ordinary attachments. A configured document Task
        // additionally has a strict Task Pack requirement that can satisfy
        // workflow readiness. Keep those two concepts separate.
        $canViewDocuments = (bool) ($actor
            && $authorization->allows($actor, 'documents', 'view', $task->job));
        $canUploadAttachment = (bool) ($actor
            && $authorization->allows($actor, 'documents', 'create', $task->job)
            && $authorization->allows($actor, 'documents', 'link', $task->job));
        $canUploadDocument = (bool) ($canUploadAttachment && $isAttachmentTask
            && (int) $task->workflow_phase_id === (int) $task->job?->current_phase_id);

        $documentRows = collect();
        if ($canViewDocuments) {
            $task->loadMissing([
                'job.documents.category:id,name',
                'job.documents.uploader:id,name,initials',
                'job.documents.versions.uploader:id,name,initials',
            ]);

            $documentRows = ($task->job?->documents ?? collect())
                ->filter(function (FlowDocument $document) use ($task, $documentSource, $documentCategory, $isConfiguredDocumentTask): bool {
                    // Explicit task links are authoritative for both required
                    // documents and ordinary task attachments.
                    if ($document->flow_task_id) {
                        return (int) $document->flow_task_id === (int) $task->id;
                    }

                    // Backward compatibility for required Task Pack documents
                    // created before flow_task_id was persisted.
                    if (! $isConfiguredDocumentTask || (int) $document->workflow_phase_id !== (int) $task->workflow_phase_id) {
                        return false;
                    }
                    if ((int) $document->document_category_id !== (int) $documentCategory?->id) {
                        return false;
                    }
                    if ($documentSource === 'task_pack_item') {
                        return (int) ($document->task_pack_item_id ?? 0) === (int) ($task->task_pack_item_id ?? 0);
                    }
                    return false;
                })
                ->sortByDesc('id')
                ->map(function (FlowDocument $document) use ($authorization, $actor): array {
                    $versions = $document->versions->sortByDesc('version')->values();

                    return [
                        'dbId' => $document->id,
                        'id' => $document->code,
                        'name' => $versions->first()?->original_name ?? $document->name,
                        'category' => $document->category?->name ?? 'Document',
                        'categoryId' => $document->document_category_id ? (int) $document->document_category_id : null,
                        'taskId' => $document->flow_task_id ? (int) $document->flow_task_id : null,
                        'canDelete' => $authorization->allows($actor, 'documents', 'delete', $document),
                        'uploadedBy' => $document->uploader?->name ?? 'System',
                        'uploadedAt' => $document->created_at?->toIso8601String(),
                        'versionCount' => $versions->count(),
                        'versions' => $versions->map(function ($version) use ($authorization, $actor, $document): array {
                            $mimeType = strtolower((string) $version->mime_type);
                            $previewable = $mimeType === 'application/pdf' || str_starts_with($mimeType, 'image/');

                            return [
                                'id' => $version->id,
                                'version' => (int) $version->version,
                                'originalName' => $version->original_name,
                                'mimeType' => $mimeType,
                                'size' => (int) $version->size,
                                'notes' => $version->notes ?? '',
                                'uploadedBy' => $version->uploader?->name ?? $document->uploader?->name ?? 'System',
                                'uploadedAt' => $version->created_at?->toIso8601String(),
                                'previewable' => $previewable,
                                'previewUrl' => $previewable
                                    ? route('flowtrack.documents.versions.preview', ['version' => $version->id])
                                    : null,
                                'downloadUrl' => $authorization->allows($actor, 'documents', 'export', $document)
                                    ? route('flowtrack.documents.versions.download', ['version' => $version->id])
                                    : null,
                            ];
                        })->values()->all(),
                    ];
                })
                ->values();
        }

        $mentionableUsers = collect([
            $task->job?->owner,
            $task->job?->coordinator,
            $effectiveAssignee,
        ])->merge($task->job?->members?->pluck('user') ?? collect())
            ->filter()
            ->unique('id')
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'initials' => $user->initials ?: $this->initials($user->name),
            ])
            ->values();
        $history = ($task->activities ?? collect())
            ->map(function ($activity) use ($task): array {
                $properties = is_array($activity->properties) ? $activity->properties : [];
                $changes = collect($properties['changes'] ?? [])->map(fn ($change): array => [
                    'field' => (string) ($change['field'] ?? 'Update'),
                    'from' => (string) ($change['from'] ?? '—'),
                    'to' => (string) ($change['to'] ?? '—'),
                ])->values()->all();

                if (empty($changes) && array_key_exists('from', $properties) && array_key_exists('to', $properties)) {
                    $changes[] = [
                        'field' => str_contains((string) $activity->event, 'due_date') ? 'Due date' : 'Status',
                        'from' => (string) ($properties['from'] ?? '—'),
                        'to' => (string) ($properties['to'] ?? '—'),
                    ];
                }

                $fileName = (string) ($properties['file_name'] ?? $properties['original_name'] ?? '');
                $completionDetected = str_contains((string) $activity->event, 'document_uploaded')
                    || collect($changes)->contains(function (array $change): bool {
                        return strtolower((string) ($change['field'] ?? '')) === 'status'
                            && in_array(strtoupper(trim((string) ($change['to'] ?? ''))), ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'], true);
                    });

                return [
                    'id' => $activity->id,
                    'event' => $activity->event,
                    'eventLabel' => $this->activityTitle((string) $activity->event),
                    'description' => $activity->description,
                    'actor' => $activity->user?->name ?? 'System',
                    'actorInitials' => $activity->user?->initials ?: $this->initials($activity->user?->name ?? 'System'),
                    'time' => $activity->created_at?->diffForHumans() ?? '',
                    'occurredAt' => $activity->created_at?->toIso8601String(),
                    'changes' => $changes,
                    'commentAdded' => (bool) ($properties['comment_added'] ?? false),
                    'commentText' => filled($properties['comment'] ?? null) ? (string) $properties['comment'] : null,
                    'fileName' => $fileName !== '' ? $fileName : null,
                    'documentRequirement' => $properties['document_requirement'] ?? $properties['requirement_key'] ?? null,
                    'source' => $this->label((string) ($properties['source'] ?? 'task activity')),
                    'taskId' => $task->code,
                    'taskTitle' => $task->title,
                    'isCompletionEvent' => $completionDetected,
                ];
            })
            ->sortByDesc('occurredAt')
            ->values();

        $completionActivity = collect($task->activities ?? [])
            ->first(function ($activity): bool {
                $properties = is_array($activity->properties) ? $activity->properties : [];
                $changes = collect($properties['changes'] ?? []);

                return str_contains((string) $activity->event, 'status')
                    && $changes->contains(function ($change): bool {
                        $field = strtolower((string) ($change['field'] ?? ''));
                        $to = strtoupper(trim((string) ($change['to'] ?? '')));

                        return $field === 'status' && in_array($to, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'], true);
                    });
            });
        $completionEvent = collect($task->transitionEvents ?? [])
            ->first(fn ($event): bool => in_array(strtoupper(trim((string) $event->to_value)), ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'], true));
        $completedByName = $completionActivity?->user?->name
            ?? $completionEvent?->actor?->name
            ?? ($task->completed_at ? ($effectiveAssignee?->name ?? 'System') : null);

        return [
            'dbId' => $task->id,
            'id' => $task->code,
            'job' => $task->job?->code,
            'jobDbId' => $task->flow_job_id,
            'jobTitle' => $task->job?->title,
            'jobContext' => $task->job ? [
                'dbId' => (int) $task->job->id,
                'id' => $task->job->code,
                'title' => $task->job->title,
                'client' => $task->job->client_id,
                'clientName' => $task->job->client?->name ?? 'Unknown client',
                'health' => $this->label($task->job->health ?? 'on_track'),
                'healthReason' => $task->job->health_reason ?? '',
                'delivery' => $task->job->delivery_date?->toDateString() ?? '',
                'progress' => (int) ($task->job->progress ?? 0),
                'status' => $this->label($task->job->status ?? 'active'),
                'attention' => (bool) ($task->job->attention_required ?? false),
            ] : null,
            'title' => $task->title,
            'description' => $task->description ?? '',
            'startDate' => $task->start_date?->toDateString() ?? $task->created_at?->toDateString() ?? '',
            'status' => $task->status?->name ?? 'Not Started',
            'statusId' => $task->task_status_id,
            'statusCode' => $task->status?->code,
            'statusColor' => data_get($task->status?->metadata, 'color'),
            'createdAt' => $task->created_at?->toIso8601String(),
            'createdBy' => $task->creator?->name ?? 'System',
            'createdByInitials' => $task->creator?->initials ?: $this->initials($task->creator?->name ?? 'System'),
            'updatedAt' => $task->updated_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'completedBy' => $completedByName,
            'completedByInitials' => $completedByName ? $this->initials($completedByName) : null,
            'assignee' => $effectiveAssignee?->name ?? 'Unassigned',
            'assigneeId' => $effectiveAssigneeId ? (int) $effectiveAssigneeId : null,
            'setupAssigneeId' => $task->setup_assignee_id ? (int) $task->setup_assignee_id : null,
            'setupAssignee' => $task->setupAssignee?->name ?? null,
            'assigneeOverridden' => (bool) $task->assignee_overridden_at,
            'canUpdate' => $actor ? $authorization->canUpdateTask($actor, $task) : false,
            'canAssign' => $actor ? $authorization->canAssignExistingTask($actor, $task) : false,
            'canDelete' => $actor ? $authorization->canDeleteTask($actor, $task) : false,
            'canManageChecklist' => $actor ? $authorization->canManageTaskChecklist($actor, $task) : false,
            'canChangeDueDate' => $actor ? $authorization->canChangeTaskDueDate($actor, $task) : false,
            'dept' => $membership?->department?->name ?? 'Unassigned',
            'due' => $task->due_date?->toDateString() ?? '',
            'priority' => $task->priority?->name ?? 'Medium',
            'priorityId' => $task->priority_id,
            'priorityColor' => data_get($task->priority?->metadata, 'color'),
            'progress' => (int) $task->progress,
            'phase' => $task->phase?->name ?? $task->phase?->short_name ?? 'General',
            'phaseName' => $task->phase?->name ?? 'General',
            'phaseShortName' => $task->phase?->short_name ?? '',
            'taskPackName' => $task->phase?->taskPack?->name ?? null,
            'phaseIndex' => max(0, ((int) ($task->phase?->sequence ?? 1)) - 1),
            'phaseId' => $task->workflow_phase_id,
            'sortOrder' => (int) $task->sort_order,
            'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
            'taskPackItemTitle' => $task->packItem?->title,
            'attention' => (bool) $task->attention_required,
            'blockerReason' => $task->blocker_reason ?? '',
            'dependency' => $task->dependency ? [
                'dbId' => (int) $task->dependency->id,
                'id' => $task->dependency->code,
                'title' => $task->dependency->title,
                'status' => $task->dependency->status?->name ?? ($task->dependency->completed_at ? 'Completed' : 'Not Started'),
                'statusCode' => $task->dependency->status?->code,
                'statusColor' => data_get($task->dependency->status?->metadata, 'color'),
                'completed' => (bool) $task->dependency->completed_at,
            ] : null,
            'dependents' => $task->dependents->map(fn (FlowTask $dependent): array => [
                'dbId' => (int) $dependent->id,
                'id' => $dependent->code,
                'title' => $dependent->title,
                'status' => $dependent->status?->name ?? ($dependent->completed_at ? 'Completed' : 'Not Started'),
                'statusCode' => $dependent->status?->code,
                'statusColor' => data_get($dependent->status?->metadata, 'color'),
                'completed' => (bool) $dependent->completed_at,
            ])->values()->all(),
            'isDocumentTask' => (bool) $isAttachmentTask,
            'documentRequirement' => $isConfiguredDocumentTask ? [
                'key' => "task:{$task->task_pack_item_id}:{$documentCategory->id}",
                'source' => $documentSource,
                'sourceLabel' => 'Task: '.($task->packItem?->title ?? $task->title),
                'phaseId' => (int) $task->workflow_phase_id,
                'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
                'taskId' => (int) $task->id,
                'categoryId' => (int) $documentCategory->id,
                'categoryName' => $documentCategory->name,
                'genericAttachment' => false,
                'satisfied' => $documentRequirementSatisfied,
            ] : null,
            'documentRequirementSatisfied' => $documentRequirementSatisfied,
            'statusChangeBlockedByDocument' => $isConfiguredDocumentTask && ! $documentRequirementSatisfied,
            'statusChangeBlockMessage' => $isConfiguredDocumentTask && ! $documentRequirementSatisfied
                ? 'Upload or link the required '.$documentCategory->name.' document before changing this Task status.'
                : null,
            'documents' => $documentRows->all(),
            'canUploadDocument' => $canUploadDocument,
            'canUploadAttachment' => $canUploadAttachment,
            'mentionableUsers' => $mentionableUsers->all(),
            'history' => $history->all(),
            'checklist' => $task->checklistItems
                ->sortBy(fn ($item): string => sprintf('%010d-%010d', (int) $item->sort_order, (int) $item->id))
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'text' => $item->label,
                    'done' => (bool) $item->is_completed,
                ])->values()->all(),
            'comments' => $task->comments
                ->sortByDesc('created_at')
                ->map(fn ($comment) => [
                    'id' => $comment->id,
                    'user' => $comment->user?->name ?? 'System',
                    'userInitials' => $comment->user?->initials ?: $this->initials($comment->user?->name ?? 'System'),
                    'text' => $comment->body,
                    'time' => $comment->created_at?->diffForHumans() ?? '',
                    'occurredAt' => $comment->created_at?->toIso8601String(),
                ])->values()->all(),
            '_detailLoaded' => true,
        ];
    }

    protected function looksLikeDocumentTask(FlowTask $task): bool
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $task->title,
            $task->description,
            $task->packItem?->title,
        ])));

        return Str::contains($haystack, [
            'quotation', 'quote', 'artwork', 'proof', 'sample', 'invoice', 'document',
            'attachment', 'file', 'purchase order', 'packing list', 'shipment document',
            'certificate', 'specification', 'requirement', 'approval sheet',
        ]);
    }

    protected function mapDocument(FlowDocument $document): array
    {
        $currentVersion = $document->currentVersion;
        $mimeType = (string) ($currentVersion?->mime_type ?? '');
        $canPreview = $currentVersion && (
            str_starts_with($mimeType, 'image/')
            || $mimeType === 'application/pdf'
        );

        return [
            'dbId' => $document->id,
            'id' => $document->code,
            'name' => $currentVersion?->original_name ?? $document->name,
            'type' => $document->category?->name ?? 'Other',
            'categoryId' => $document->document_category_id,
            'phaseId' => $document->workflow_phase_id,
            'phase' => $document->phase?->name,
            'taskPackItemId' => $document->task_pack_item_id,
            'taskId' => $document->flow_task_id ? (int) $document->flow_task_id : null,
            'taskCode' => $document->task?->code,
            'taskTitle' => $document->task?->title,
            'taskRequirement' => $document->task?->title ?? $document->taskPackItem?->title,
            'job' => $document->job?->code,
            'client' => $document->job?->client_id,
            'version' => 'v'.($currentVersion?->version ?? 1),
            'versionNumber' => $currentVersion?->version ?? 1,
            'mimeType' => $mimeType,
            'size' => $currentVersion?->size ?? 0,
            'uploadedBy' => $document->uploader?->name ?? 'System',
            'date' => $currentVersion?->created_at?->toDateString() ?? $document->created_at?->toDateString() ?? '',
            'final' => (bool) $document->is_final,
            'previewUrl' => $canPreview ? route('flowtrack.documents.versions.preview', ['version' => $currentVersion->id]) : null,
            'downloadUrl' => $currentVersion ? route('flowtrack.documents.versions.download', ['version' => $currentVersion->id]) : null,
        ];
    }

    protected function mapWorkflow(WorkflowTemplate $workflow): array
    {
        return [
            'id' => $workflow->id,
            'code' => $workflow->code,
            'name' => $workflow->name,
            'description' => $workflow->description ?? '',
            'active' => (bool) $workflow->is_active,
            'isActive' => (bool) $workflow->is_active,
            'isDefault' => (bool) $workflow->is_default,
            'version' => (int) $workflow->version,
            'phases' => $workflow->phases->map(fn (WorkflowPhase $phase) => [
                'id' => $phase->id,
                'name' => $phase->name,
                'short' => $phase->short_name,
                'shortName' => $phase->short_name,
                'taskPack' => $phase->taskPack?->name ?? '',
                'taskPackId' => $phase->task_pack_id,
                'taskCount' => $phase->taskPack?->items?->count() ?? 0,
                'allowStart' => (bool) $phase->allow_job_start,
                'skippable' => (bool) $phase->is_skippable,
                'autoAdvance' => (bool) $phase->auto_advance_on_ready,
                'active' => (bool) $phase->is_active,
                'entry' => $phase->entry_condition ?? '',
                'exit' => $phase->exit_condition ?? '',
                'document' => $phase->documentCategory?->name ?? '',
                'documentCategoryId' => $phase->document_category_id,
                'sequence' => (int) $phase->sequence,
            ])->values()->all(),
        ];
    }

    protected function mapTaskPack(TaskPack $taskPack): array
    {
        return [
            'id' => $taskPack->id,
            'code' => $taskPack->code,
            'name' => $taskPack->name,
            'description' => $taskPack->description ?? '',
            'active' => (bool) $taskPack->is_active,
            'is_active' => (bool) $taskPack->is_active,
            'items' => $taskPack->items->map(fn (TaskPackItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description ?? '',
                'default_assignee_id' => $item->default_assignee_id,
                'default_assignee_name' => $item->defaultAssignee?->name,
                'default_department_id' => $item->default_department_id,
                'default_department_name' => $item->defaultDepartment?->name,
                'priority_id' => $item->priority_id,
                'priority_name' => $item->priority?->name,
                'document_category_id' => $item->document_category_id,
                'document_category_name' => $item->documentCategory?->name,
                'due_offset_days' => (int) $item->due_offset_days,
                'is_required' => (bool) $item->is_required,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all(),
        ];
    }

    protected function mapMasterData(Collection $master): array
    {
        return collect(MasterDataType::cases())
            ->mapWithKeys(fn (MasterDataType $type): array => [
                $type->key() => $master->get($type->value, collect())->values()->all(),
            ])
            ->all();
    }


    /**
     * Return one chronological audit stream for the Job, including direct Job
     * updates and every related Task/document add, edit and delete event.
     */
    protected function jobActivity(FlowJob $job): array
    {
        if (! array_key_exists((int) $job->id, $this->jobActivityCache)) {
            $this->primeJobActivityCache(collect([$job]));
        }

        return $this->jobActivityCache[(int) $job->id] ?? [];
    }

    /**
     * Load activity for a page of Jobs in a bounded set of queries. This avoids
     * reintroducing an N+1 problem in the paginated Job bootstrap segment.
     *
     * @param Collection<int, FlowJob> $jobs
     */
    protected function primeJobActivityCache(Collection $jobs): void
    {
        $jobs = $jobs->filter(fn ($job): bool => $job instanceof FlowJob)->values();
        $jobIds = $jobs->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($jobIds->isEmpty()) {
            return;
        }

        $workspaceIds = $jobs->pluck('workspace_id')->map(fn ($id): int => (int) $id)->unique()->values();

        $activities = FlowActivity::query()
            ->with('user:id,name,initials')
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('flow_job_id', $jobIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $activitiesByJob = $activities->groupBy(fn (FlowActivity $activity): int => (int) $activity->flow_job_id);

        foreach ($jobs as $job) {
            $jobActivities = collect($activitiesByJob[(int) $job->id] ?? []);
            $rows = $jobActivities->map(fn (FlowActivity $activity): array => $this->mapJobActivityRow($activity));

            $fallbackTransitions = $job->transitionEvents
                ->reject(fn ($event): bool => $this->transitionRepresentedByActivity($event, $jobActivities))
                ->map(fn ($event): array => [
                    'id' => 'transition-'.$event->id,
                    'event' => (string) $event->transition_type,
                    'kind' => str_starts_with((string) $event->transition_type, 'task.') ? 'worklog' : 'history',
                    'comment' => null,
                    'title' => $this->activityTitle((string) $event->transition_type),
                    'text' => trim(collect([
                        $event->from_value && $event->to_value
                            ? "{$event->from_value} → {$event->to_value}"
                            : ($event->to_value ?: null),
                        $event->reason,
                    ])->filter()->join(' · ')),
                    'time' => $event->occurred_at?->diffForHumans() ?? '',
                    'source' => $this->label($event->source),
                    'actor' => $event->actor?->name ?? 'System',
                    'actorInitials' => $event->actor?->initials ?: $this->initials($event->actor?->name ?? 'System'),
                    'occurredAt' => $event->occurred_at?->toIso8601String(),
                    '_timestamp' => $event->occurred_at?->getTimestamp() ?? 0,
                ]);

            $this->jobActivityCache[(int) $job->id] = $rows
                ->concat($fallbackTransitions)
                ->sortByDesc('_timestamp')
                ->values()
                ->map(fn (array $row): array => collect($row)->except(['_timestamp'])->all())
                ->all();
        }
    }

    protected function mapJobActivityRow(FlowActivity $activity): array
    {
        $properties = is_array($activity->properties) ? $activity->properties : [];
        $changes = collect($properties['changes'] ?? [])
            ->map(function ($change): string {
                $field = trim((string) ($change['field'] ?? 'Update'));
                $from = trim((string) ($change['from'] ?? '—'));
                $to = trim((string) ($change['to'] ?? '—'));

                return "{$field}: {$from} → {$to}";
            })
            ->filter()
            ->join(' · ');

        $event = (string) $activity->event;
        $isComment = $event === 'job.commented' || $event === 'task.commented' || (bool) ($properties['comment_added'] ?? false);

        return [
            'id' => 'activity-'.$activity->id,
            'event' => $event,
            'kind' => $isComment ? 'comment' : (str_starts_with($event, 'task.') ? 'worklog' : 'history'),
            'title' => $this->activityTitle($event),
            'text' => trim(collect([$activity->description, $changes ?: null])->filter()->join(' · ')),
            'comment' => filled($properties['comment'] ?? null) ? (string) $properties['comment'] : ($event === 'job.commented' ? $activity->description : null),
            'time' => $activity->created_at?->diffForHumans() ?? '',
            'source' => $this->label((string) ($properties['source'] ?? 'activity log')),
            'actor' => $activity->user?->name ?? 'System',
            'actorInitials' => $activity->user?->initials ?: $this->initials($activity->user?->name ?? 'System'),
            'occurredAt' => $activity->created_at?->toIso8601String(),
            '_timestamp' => $activity->created_at?->getTimestamp() ?? 0,
        ];
    }

    protected function transitionRepresentedByActivity($event, Collection $activities): bool
    {
        $activityEvent = match ((string) $event->transition_type) {
            'job.initialized' => 'job.created',
            'job.phase' => 'job.phase_changed',
            'task.status' => 'task.status_changed',
            default => null,
        };

        if (! $activityEvent || ! $event->occurred_at) {
            return false;
        }

        return $activities->contains(function (FlowActivity $activity) use ($event, $activityEvent): bool {
            $matchesEvent = (string) $activity->event === $activityEvent
                || ($activityEvent === 'task.status_changed'
                    && in_array((string) $activity->event, ['task.status_changed', 'task.document_uploaded'], true));
            if (! $matchesEvent) {
                return false;
            }

            return (int) ($activity->user_id ?? 0) === (int) ($event->actor_id ?? 0)
                && abs(($activity->created_at?->getTimestamp() ?? 0) - $event->occurred_at->getTimestamp()) <= 10;
        });
    }

    protected function activityTitle(string $event): string
    {
        return Str::of($event)
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();
    }

    protected function authorizeClientSensitiveData(User $user, array $data, ?Client $client = null): void
    {
        $authorization = app(FlowAuthorizationService::class);
        $contactFields = ['contact_name', 'email', 'phone', 'notes'];
        $contactChanged = collect($contactFields)->contains(function (string $field) use ($data, $client): bool {
            if (! array_key_exists($field, $data)) return false;
            return $client === null ? filled($data[$field]) : (string) ($data[$field] ?? '') !== (string) ($client->{$field} ?? '');
        });
        if ($contactChanged) {
            abort_unless($authorization->canViewSensitiveField($user, 'client_contact_details'), 403);
        }

        if (array_key_exists('outstanding_balance', $data)) {
            $changed = $client === null
                ? (float) ($data['outstanding_balance'] ?? 0) !== 0.0
                : (float) ($data['outstanding_balance'] ?? 0) !== (float) $client->outstanding_balance;
            if ($changed) {
                abort_unless($this->canViewFinancials($user), 403);
            }
        }
    }

    protected function canViewFinancials(User $user): bool
    {
        $authorization = app(FlowAuthorizationService::class);

        return $authorization->allows($user, 'invoice', 'view')
            && $authorization->canViewSensitiveField($user, 'invoice_amount');
    }

    protected function label(?string $value): string
    {
        return Str::of((string) $value)->replace(['_', '-'], ' ')->title()->toString();
    }

    protected function initials(string $name): string
    {
        return Str::of($name)->explode(' ')->filter()->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->implode('');
    }
}
