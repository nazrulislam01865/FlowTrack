<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreClientRequest;
use App\Http\Requests\FlowTrack\StoreJobRequest;
use App\Http\Requests\FlowTrack\StoreDocumentRequest;
use App\Http\Requests\FlowTrack\UpdateTaskRequest;
use App\Http\Requests\FlowTrack\UpdateTaskDueDateRequest;
use App\Http\Requests\FlowTrack\UpdateJobRequest;
use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateClientAction;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\Actions\MoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\RemoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\RemoveTaskFromJobAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskDueDateAction;
use App\Modules\OrderFlow\Application\Actions\UpdateJobAction;
use App\Modules\OrderFlow\Application\Actions\UploadDocumentAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Application\DTOs\UpdateJobData;
use App\Modules\OrderFlow\Application\Presenters\CurrentUserPresenter;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService;
use App\Modules\OrderFlow\Business\Services\OperationalMetricsService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TemplateApiController extends Controller
{
    public function __construct(private readonly JobDocumentRequirementService $documentRequirements) {}

    public function bootstrap(Request $request, ProgressCalculator $progressCalculator, WorkflowAutomationService $workflowAutomation, RoleMatrixService $roleMatrixService, FlowAuthorizationService $authorization, RoleMatrixCatalog $roleCatalog, CurrentUserPresenter $currentUserPresenter, OperationalMetricsService $metrics, NotificationFeedService $notificationFeed): JsonResponse
    {
        $user = $request->user();
        $workspaceId = $user->currentWorkspaceId();
        $canViewFinancials = $authorization->allows($user, 'invoice', 'view')
            && $authorization->canViewSensitiveField($user, 'invoice_amount');
        $canViewClientContacts = $authorization->canViewSensitiveField($user, 'client_contact_details');
        $canViewWorkflowSetup = $authorization->allows($user, 'workflow', 'view');
        $canCreateJobs = $authorization->allows($user, 'jobs', 'create');
        $canViewMasterData = $authorization->allows($user, 'masterdata', 'view');
        $canUseOperationalMasterData = $canViewMasterData
            || $canCreateJobs
            || $authorization->allows($user, 'tasks', 'create')
            || $authorization->allows($user, 'documents', 'create')
            || $canViewWorkflowSetup;

        $canViewUsers = $user->hasFlowAction('users', 'view');
        $canUseAssignments = collect([
            ['jobs', 'assign'], ['jobs', 'create'], ['tasks', 'assign'], ['tasks', 'create'], ['clients', 'assign'],
        ])->contains(fn (array $permission): bool => $user->hasFlowAction($permission[0], $permission[1]));

        $memberships = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->when(! $canViewUsers && ! $canUseAssignments, fn (Builder $query) => $query->where('user_id', $user->id))
            ->with(['user:id,name,initials,email,locale', 'role:id,name', 'department:id,name'])
            ->get();

        $users = $memberships->map(fn (WorkspaceMembership $membership) => [
            'id' => $membership->user->id,
            'name' => $membership->user->name,
            'initials' => $membership->user->initials ?: $this->initials($membership->user->name),
            'email' => $canViewUsers ? $membership->user->email : null,
            'role' => $membership->role?->name ?? 'Member',
            'dept' => $membership->department?->name ?? 'Unassigned',
            'language' => $membership->user->locale ?: 'en',
        ])->values();

        $clientsQuery = $authorization->applyClientVisibility(Client::query(), $user);
        $clients = $clientsQuery
            ->where('status', 'active')
            ->with('accountManager:id,name,initials')
            ->withCount([
                'jobs as active_jobs_count' => fn (Builder $query) => $authorization->applyJobVisibility($query, $user)->where('status', '!=', 'completed'),
                'jobs as attention_jobs_count' => fn (Builder $query) => $authorization->applyJobVisibility($query, $user)->where('attention_required', true),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client) => $this->mapClient($client, $canViewFinancials, $canViewClientContacts));

        $workflows = ($canViewWorkflowSetup || $canCreateJobs)
            ? WorkflowTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->when(! $canViewWorkflowSetup, fn (Builder $query) => $query->where('is_active', true))
                ->with([
                    'phases' => fn ($query) => $query
                        ->when(! $canViewWorkflowSetup, fn (Builder $phase) => $phase->where('is_active', true))
                        ->orderBy('sequence'),
                    'phases.taskPack.items.documentCategory',
                    'phases.documentCategory:id,name',
                ])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
            : collect();

        $jobs = FlowJob::query()
            ->visibleTo($user)
            ->with([
                'client:id,name,code', 'workflow.phases', 'currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory', 'startedPhase',
                'product:id,name,code', 'productCategory:id,name,code', 'items.product:id,name,code', 'items.productCategory:id,name,code', 'currency:id,name,code',
                'priority:id,name,code', 'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
                'members.user:id,name,initials', 'excludedPhases.phase:id,name,short_name,sequence',
                'phaseHistory.phase:id,sequence',
                'transitionEvents.actor:id,name,initials', 'transitionEvents.phase:id,name,short_name', 'transitionEvents.task:id,code,title',
            ])
            ->latest('id')
            ->get();

        $boardJobIds = $authorization->applyJobBoardVisibility(FlowJob::query(), $user)
            ->where('status', '!=', 'completed')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        // Keep existing Jobs aligned with their currently configured Task Pack.
        // This repairs Jobs created before a pack was assigned/expanded and makes
        // the generated Tasks immediately visible in Job Tasks and the Task Board.
        $jobs->each(function (FlowJob $job) use ($workflowAutomation, $user): void {
            if (! $job->currentPhase) {
                return;
            }

            $workflowAutomation->synchronize($job, $user, 'template_bootstrap_repair', false);
            $job->unsetRelation('phaseHistory');
            $job->load('phaseHistory.phase:id,sequence');
        });

        $tasks = $authorization->allows($user, 'tasks', 'view')
            ? $authorization->applyTaskVisibility(FlowTask::query(), $user)
            ->with([
                'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id', 'job.client:id,name', 'job.members',
                'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
                'creator:id,name,initials',
                'assignee:id,name,initials', 'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $workspaceId)->with('department:id,name'),
                'status:id,name,code', 'priority:id,name,code', 'checklistItems', 'comments.user:id,name,initials',
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            : collect();

        $tasks->each(function (FlowTask $task) use ($progressCalculator) {
            $derivedProgress = $progressCalculator->forTaskStatus($task->status);
            if ((int) $task->progress !== $derivedProgress) {
                $task->forceFill(['progress' => $derivedProgress])->saveQuietly();
            }
        });
        $jobs->each(fn (FlowJob $job) => $progressCalculator->recalculateJob($job));


        $documents = $authorization->allows($user, 'documents', 'view')
            ? $authorization->applyDocumentVisibility(FlowDocument::query(), $user)
            ->with(['job:id,code,client_id,current_phase_id', 'job.client:id,name', 'phase:id,name,short_name,sequence', 'taskPackItem:id,title', 'category:id,name', 'uploader:id,name', 'currentVersion'])
            ->latest('id')
            ->get()
            : collect();

        $master = $canUseOperationalMasterData
            ? MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->when(! $canViewMasterData, fn (Builder $query) => $query->where('status', 'active'))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->groupBy('type')
                ->map(fn (Collection $records) => $records->map(fn (MasterRecord $record) => [
                    'id' => $record->id,
                    'code' => $record->code,
                    'name' => $record->name,
                    'detail' => $record->description ?? '',
                    'description' => $record->description ?? '',
                    'status' => Str::title($record->status),
                    'parent_id' => $record->parent_id,
                    'sort_order' => (int) $record->sort_order,
                ])->values())
            : collect();

        $taskPacks = $canViewWorkflowSetup
            ? TaskPack::query()
                ->where('workspace_id', $workspaceId)
                ->with([
                    'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                    'items.defaultAssignee:id,name,initials',
                    'items.defaultDepartment:id,name,code',
                    'items.priority:id,name,code',
                    'items.documentCategory:id,name,code',
                ])
                ->orderBy('name')
                ->get()
            : collect();

        $authorityNotificationView = $notificationFeed->isAuthority($user);
        $notifications = $notificationFeed->latest($user, 50)->map(function ($notification) use ($authorityNotificationView) {
            $data = $notification->data;

            return [
                'id' => $notification->id,
                'title' => $data['title'] ?? 'Notification',
                'text' => $data['text'] ?? '',
                'time' => $notification->created_at?->diffForHumans() ?? '',
                'created_at' => $notification->created_at?->toIso8601String(),
                'read' => $notification->read_at !== null,
                'type' => $data['type'] ?? 'info',
                'severity' => $data['severity'] ?? 'info',
                'event' => $data['event'] ?? null,
                'job' => $data['job'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'task' => $data['task'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'phase' => $data['phase'] ?? null,
                'actor' => $data['actor'] ?? null,
                'url' => $data['url'] ?? null,
                'audience' => $authorityNotificationView ? array_values($data['audience'] ?? []) : [],
            ];
        })->values();

        $recentActivities = $tasks
            ->sortByDesc(fn (FlowTask $task): int => $task->created_at?->getTimestamp() ?? 0)
            ->take(6)
            ->map(fn (FlowTask $task): array => [
                'id' => "task-created-{$task->id}",
                'title' => 'Task created',
                'text' => collect([$task->code, $task->title, $task->job?->code])->filter()->join(' · '),
                'time' => $task->created_at?->diffForHumans() ?? '',
                'created_at' => $task->created_at?->toIso8601String(),
                'event' => 'task.created',
                'job' => $task->job?->code,
                'job_id' => $task->flow_job_id,
                'task' => $task->code,
                'task_id' => $task->id,
                'user' => [
                    'id' => $task->creator?->id,
                    'name' => $task->creator?->name ?? 'System',
                    'initials' => $task->creator?->initials,
                ],
            ])
            ->values();

        $capabilities = collect($roleCatalog->moduleCodes())->mapWithKeys(function (string $module) use ($user, $roleCatalog): array {
            return [$module => [
                'scope' => app(FlowAuthorizationService::class)->scope($user, $module),
                'actions' => array_values(array_filter(
                    $roleCatalog->actionCodes(),
                    fn (string $action): bool => $user->hasFlowAction($module, $action),
                )),
            ]];
        });

        $accessControl = $user->hasFlowAction('users', 'view') || $user->isFlowAdministrator()
            ? [
                ...$roleMatrixService->accessPayload($workspaceId),
                'canManage' => $user->hasFlowAction('users', 'manage') || $user->isFlowAdministrator(),
                'canCreateUsers' => $user->isFlowAdministrator(),
                'canDeleteUsers' => $user->isFlowAdministrator(),
                'isAdmin' => $user->isFlowAdministrator(),
                'isSuperAdmin' => $user->isFlowSuperAdmin(),
            ]
            : null;

        return response()->json([
            'currentUser' => $currentUserPresenter->toArray($user),
            'users' => $users,
            'clients' => $clients,
            'jobs' => $jobs->map(fn (FlowJob $job) => $this->mapJob($job, $canViewFinancials)),
            'boardJobIds' => $boardJobIds,
            'boardAccess' => [
                'isAdministrator' => $authorization->isAdministrator($user),
                'taskVisibility' => $authorization->isAdministrator($user) ? 'workspace' : 'assigned_job_tasks',
            ],
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
            'documents' => $documents->map(fn (FlowDocument $document) => $this->mapDocument($document)),
            'notifications' => $notifications,
            'notificationAccess' => [
                'authorityView' => $authorityNotificationView,
                'statusEndpoint' => route('flowtrack.notifications.status'),
            ],
            'activities' => $recentActivities,
            'workflows' => $workflows->map(fn (WorkflowTemplate $workflow) => $this->mapWorkflow($workflow)),
            'masterData' => $this->mapMasterData($master),
            'masterDataCatalog' => collect(MasterDataType::cases())->map(fn (MasterDataType $type) => $type->catalogEntry())->values()->all(),
            'taskPacks' => $taskPacks->map(fn (TaskPack $taskPack) => $this->mapTaskPack($taskPack)),
            'capabilities' => $capabilities,
            'accessControl' => $accessControl,
            'summaryMetrics' => $metrics->forUser($user),
            'notificationRules' => config('flowtrack.notification_rules', []),
        ]);
    }

    public function storeClient(StoreClientRequest $request, CreateClientAction $action): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeClientSensitiveData($request->user(), $data);
        if (! empty($data['account_manager_id']) && (int) $data['account_manager_id'] !== (int) $request->user()->id) {
            abort_unless($request->user()->hasFlowAction('clients', 'assign'), 403);
        }
        $client = $action->execute($request->user()->currentWorkspaceId(), $data);
        $client->load('accountManager:id,name,initials')->loadCount([
            'jobs as active_jobs_count' => fn (Builder $query) => $query->where('status', '!=', 'completed'),
            'jobs as attention_jobs_count' => fn (Builder $query) => $query->where('attention_required', true),
        ]);

        return response()->json(['client' => $this->mapClient($client, $this->canViewFinancials($request->user()), app(FlowAuthorizationService::class)->canViewSensitiveField($request->user(), 'client_contact_details'))], 201);
    }

    public function updateClient(StoreClientRequest $request, Client $client): JsonResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validated();
        $this->authorizeClientSensitiveData($request->user(), $data, $client);
        if (array_key_exists('account_manager_id', $data) && (int) ($data['account_manager_id'] ?? 0) !== (int) ($client->account_manager_id ?? 0)) {
            abort_unless($request->user()->hasFlowAction('clients', 'assign', $client), 403);
        }
        $client->update($data);
        $client->load('accountManager:id,name,initials')->loadCount([
            'jobs as active_jobs_count' => fn (Builder $query) => $query->where('status', '!=', 'completed'),
            'jobs as attention_jobs_count' => fn (Builder $query) => $query->where('attention_required', true),
        ]);

        return response()->json(['client' => $this->mapClient($client, $this->canViewFinancials($request->user()), app(FlowAuthorizationService::class)->canViewSensitiveField($request->user(), 'client_contact_details'))]);
    }

    public function destroyClient(Request $request, Client $client): JsonResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('clients', 'delete', $client), 403);

        DB::transaction(function () use ($client): void {
            $client->jobs()->with(['tasks', 'documents'])->get()->each(function (FlowJob $job): void {
                $job->tasks()->delete();
                $job->documents()->delete();
                $job->delete();
            });
            $client->delete();
        });

        return response()->json(['message' => 'Client and its related Jobs were deleted successfully.']);
    }

    public function destroyJob(Request $request, FlowJob $job): JsonResponse
    {
        abort_unless($job->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('jobs', 'delete', $job), 403);

        DB::transaction(function () use ($job): void {
            $job->tasks()->delete();
            $job->documents()->delete();
            $job->delete();
        });

        return response()->json(['message' => 'Job deleted successfully.']);
    }

    public function destroyTask(
        Request $request,
        FlowTask $task,
        FlowAuthorizationService $authorization,
        RemoveTaskFromJobAction $action,
    ): JsonResponse
    {
        $task->loadMissing('job', 'packItem');
        abort_unless($task->job?->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($authorization->canDeleteTask($request->user(), $task), 403);
        $job = $action->execute($task, $request->user(), 'exact_template_task_deletion');

        return response()->json([
            'message' => 'Task deleted successfully.',
            'job' => $this->mapJob($this->loadJob($job), $this->canViewFinancials($request->user())),
            'tasks' => $this->loadJobTasks($job, $request->user())->map(fn (FlowTask $jobTask) => $this->mapTask($jobTask)),
        ]);
    }

    public function storeDocument(
        StoreDocumentRequest $request,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadDocumentAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $job = $jobs->findVisible($request->user(), (int) $data['job_id']);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $job), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $job), 403);

        $document = $action->execute(
            $job,
            $request->file('file'),
            isset($data['document_category_id']) ? (int) $data['document_category_id'] : null,
            (bool) ($data['is_final'] ?? false),
            $request->user(),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            $data['notes'] ?? null,
            (int) $data['workflow_phase_id'],
            isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
            (bool) ($data['advance_phase'] ?? false),
        );

        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($job);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        $workflowResult = $action->workflowResult();
        $message = 'Document uploaded and linked to the current Workflow Phase.';
        if ($workflowResult['moved'] ?? false) {
            $message = 'Document uploaded, linked, and the Job moved to the next Workflow Phase.';
        } elseif ((bool) ($data['advance_phase'] ?? false) && ! ($workflowResult['ready'] ?? false) && ! empty($workflowResult['blockers'])) {
            $message = 'Document uploaded and linked. The Job remains in its current Phase: '.implode(' ', $workflowResult['blockers']);
        }

        return response()->json([
            'message' => $message,
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function destroyDocument(Request $request, FlowDocument $document, WorkflowAutomationService $automation): JsonResponse
    {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('documents', 'delete', $document), 403);
        $job = $document->job;
        $document->delete();
        if ($job) {
            $automation->synchronize($job, $request->user(), 'document_deletion', false);
        }

        return response()->json(['message' => 'Document deleted successfully.']);
    }

    public function storeJob(StoreJobRequest $request, CreateJobAction $action): JsonResponse
    {
        $data = $request->validated();
        $assignedIds = collect([$data['owner_id'] ?? null, $data['coordinator_id'] ?? null])
            ->merge($data['member_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique();
        if ($assignedIds->contains(fn (int $id) => $id !== (int) $request->user()->id)) {
            abort_unless($request->user()->hasFlowAction('jobs', 'assign'), 403);
        }

        $productItems = collect($data['products'] ?? [])->map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'product_category_id' => isset($item['product_category_id']) ? (int) $item['product_category_id'] : null,
            'quantity' => (int) $item['quantity'],
        ])->values();
        $primaryProduct = $productItems->first();
        $submittedQuantity = isset($data['quantity'])
            ? (int) $data['quantity']
            : max(1, (int) $productItems->sum('quantity'));

        try {
            $job = $action->execute(new CreateJobData(
                workspaceId: $request->user()->currentWorkspaceId(),
                clientId: (int) $data['client_id'],
                workflowTemplateId: (int) $data['workflow_template_id'],
                startPhaseId: (int) $data['start_phase_id'],
                productId: $primaryProduct['product_id'] ?? (isset($data['product_id']) ? (int) $data['product_id'] : null),
                productCategoryId: $primaryProduct['product_category_id'] ?? (isset($data['product_category_id']) ? (int) $data['product_category_id'] : null),
                currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                priorityId: isset($data['priority_id']) ? (int) $data['priority_id'] : null,
                ownerId: isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                coordinatorId: isset($data['coordinator_id']) ? (int) $data['coordinator_id'] : null,
                title: $data['title'],
                requestDescription: $data['request_description'] ?? null,
                quantity: $submittedQuantity,
                quotedAmount: (float) ($data['quoted_amount'] ?? 0),
                deliveryDate: $data['delivery_date'] ?? null,
                startReason: $data['start_reason'] ?? null,
                memberIds: $data['member_ids'] ?? [],
                draft: (bool) ($data['draft'] ?? false),
                productItems: $productItems->all(),
            ), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        }

        $job = $this->loadJob($job);
        $tasks = $this->loadJobTasks($job, $request->user());

        return response()->json([
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function updateJob(
        UpdateJobRequest $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UpdateJobAction $action,
        FlowNotificationService $notifications,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        $data = $request->validated();

        $assignmentChanged = (int) ($data['owner_id'] ?? 0) !== (int) ($visible->owner_id ?? 0)
            || (int) ($data['coordinator_id'] ?? 0) !== (int) ($visible->coordinator_id ?? 0);
        if ($assignmentChanged) {
            abort_unless($authorization->allows($request->user(), 'jobs', 'assign', $visible), 403);
        }

        $previousMemberIds = $visible->members()->pluck('user_id')->map(fn ($id) => (int) $id);
        $productItems = collect($data['products'])->map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'product_category_id' => (int) $item['product_category_id'],
            'quantity' => (int) $item['quantity'],
        ])->values();

        try {
            $updated = $action->execute($visible, new UpdateJobData(
                clientId: (int) $data['client_id'],
                currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                priorityId: isset($data['priority_id']) ? (int) $data['priority_id'] : null,
                ownerId: isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                coordinatorId: isset($data['coordinator_id']) ? (int) $data['coordinator_id'] : null,
                title: $data['title'],
                requestDescription: $data['request_description'] ?? null,
                quotedAmount: array_key_exists('quoted_amount', $data) && $data['quoted_amount'] !== null ? (float) $data['quoted_amount'] : null,
                deliveryDate: $data['delivery_date'] ?? null,
                productItems: $productItems->all(),
            ), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        }

        $newMemberIds = $updated->members()->pluck('user_id')->map(fn ($id) => (int) $id);
        $newlyAssigned = $newMemberIds->diff($previousMemberIds)->values();
        if ($newlyAssigned->isNotEmpty()) {
            DB::afterCommit(fn () => $notifications->jobMembersChanged($updated->id, $request->user()->id, $newlyAssigned->all()));
        }

        $updated = $this->loadJob($updated);
        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $this->loadJobTasks($updated, $request->user())->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function updateTask(
        UpdateTaskRequest $request,
        FlowTask $task,
        TaskRepository $tasks,
        FlowAuthorizationService $authorization,
        UpdateTaskAction $action,
    ): JsonResponse {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->canUpdateTask($request->user(), $visibleTask), 403);

        $data = $request->validated();
        if (array_key_exists('assignee_id', $data) && (int) ($data['assignee_id'] ?? 0) !== (int) ($visibleTask->assignee_id ?? 0)) {
            abort_unless($authorization->canAssignExistingTask($request->user(), $visibleTask), 403);
        }

        try {
            $updated = $action->execute($visibleTask, $data, $request->user(), 'exact_template_task_drawer');
        } catch (DomainException $exception) {
            $message = $exception->getMessage();
            $field = str_contains(strtolower($message), 'status')
                ? 'task_status_id'
                : (str_contains(strtolower($message), 'assignee') ? 'assignee_id' : 'blocker_reason');

            throw ValidationException::withMessages([$field => $message]);
        }

        $job = $this->loadJob($updated->job);
        $updated->load(['job.client', 'phase.taskPack', 'assignee.workspaceMemberships.department', 'status', 'priority', 'checklistItems', 'comments.user']);

        $jobTasks = $this->loadJobTasks($job, $request->user());

        return response()->json([
            'task' => $this->mapTask($updated),
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
            'tasks' => $jobTasks->map(fn (FlowTask $jobTask) => $this->mapTask($jobTask)),
        ]);
    }

    public function updateTaskDueDate(
        UpdateTaskDueDateRequest $request,
        FlowTask $task,
        TaskRepository $tasks,
        FlowAuthorizationService $authorization,
        UpdateTaskDueDateAction $action,
    ): JsonResponse {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->canChangeTaskDueDate($request->user(), $visibleTask), 403);

        $updated = $action->execute(
            $visibleTask,
            $request->validated('due_date'),
            $request->user(),
            'exact_template_task_due_date',
        );
        $job = $this->loadJob($updated->job);

        return response()->json([
            'message' => 'Task due date updated.',
            'task' => $this->mapTask($updated),
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
            'tasks' => $this->loadJobTasks($job, $request->user())->map(fn (FlowTask $jobTask) => $this->mapTask($jobTask)),
        ]);
    }

    public function moveJob(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        AccessResolver $access,
        MoveJobPhaseAction $action,
    ): JsonResponse {
        $data = $request->validate([
            'phase_id' => ['required', 'integer', 'exists:workflow_phases,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $visible = $jobs->findBoardVisible($request->user(), $job->id);
        abort_unless($access->canManageJob($request->user(), $visible), 403);

        try {
            $updated = $action->execute($visible, (int) $data['phase_id'], $request->user(), $data['reason'] ?? null, 'exact_template_job_phase_board');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['phase_id' => $exception->getMessage()]);
        }

        $updated = $this->loadJob($updated);
        $tasks = $this->loadJobTasks($updated, $request->user());

        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function destroyJobPhase(
        Request $request,
        FlowJob $job,
        WorkflowPhase $phase,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        RemoveJobPhaseAction $action,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->canDeleteJobPhase($request->user(), $visible), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $action->execute($visible, $phase, $request->user(), $data['reason'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['phase' => $exception->getMessage()]);
        }

        $updated = $this->loadJob($updated);

        return response()->json([
            'message' => "{$phase->name} was removed from this Job.",
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $this->loadJobTasks($updated, $request->user())->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    private function loadJob(FlowJob $job): FlowJob
    {
        return $job->fresh([
            'client:id,name,code', 'workflow.phases', 'currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory', 'startedPhase',
            'product:id,name,code', 'productCategory:id,name,code', 'items.product:id,name,code', 'items.productCategory:id,name,code', 'currency:id,name,code',
            'priority:id,name,code', 'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
            'members.user:id,name,initials', 'excludedPhases.phase:id,name,short_name,sequence', 'phaseHistory.phase:id,sequence',
            'transitionEvents.actor:id,name,initials', 'transitionEvents.phase:id,name,short_name', 'transitionEvents.task:id,code,title',
        ]);
    }

    private function loadJobTasks(FlowJob $job, User $user): Collection
    {
        return app(FlowAuthorizationService::class)
            ->applyTaskVisibility(FlowTask::query(), $user)
            ->where('flow_job_id', $job->id)
            ->with([
                'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id', 'job.client:id,name', 'job.members',
                'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
                'assignee:id,name,initials', 'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $job->workspace_id)->with('department:id,name'),
                'status:id,name,code', 'priority:id,name,code', 'checklistItems', 'comments.user:id,name,initials',
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    private function mapClient(Client $client, bool $canViewFinancials = false, bool $canViewContacts = false): array
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

    private function mapJob(FlowJob $job, bool $canViewFinancials = false): array
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
            'createdAt' => $job->created_at?->toIso8601String(),
            'updatedAt' => $job->updated_at?->toIso8601String(),
            'health' => $this->label($job->health),
            'healthReason' => $job->health_reason ?? '',
            'progress' => (int) $job->progress,
            'owner' => $job->owner?->name ?? 'Unassigned',
            'ownerId' => $job->owner_id,
            'coordinator' => $job->coordinator?->name ?? 'Unassigned',
            'coordinatorId' => $job->coordinator_id,
            'createdById' => $job->created_by,
            'createdBy' => $job->creator?->name ?? 'Unknown',
            'canDeletePhases' => request()->user()
                ? app(FlowAuthorizationService::class)->canDeleteJobPhase(request()->user(), $job)
                : false,
            'excludedPhaseIds' => $job->excludedPhases
                ->pluck('workflow_phase_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'delivery' => $job->delivery_date?->toDateString() ?? '',
            'priority' => $job->priority?->name ?? 'Medium',
            'priorityId' => $job->priority_id,
            'next' => $job->next_action ?? 'Review current phase',
            'milestone' => $job->milestone ?? '',
            'attention' => (bool) $job->attention_required,
            'phaseReadiness' => optional($job->phaseHistory->firstWhere('workflow_phase_id', $job->current_phase_id))->readiness_status ?? 'pending',
            'phaseReadinessLabel' => $this->label(optional($job->phaseHistory->firstWhere('workflow_phase_id', $job->current_phase_id))->readiness_status ?? 'pending'),
            'phaseReadinessDetails' => optional($job->phaseHistory->firstWhere('workflow_phase_id', $job->current_phase_id))->readiness_details ?? [],
            'documentRequirements' => $this->documentRequirements->requirements($job)->values()->all(),
            'workflow' => $job->workflow_template_id,
            'createdFromPhase' => max(0, ((int) ($job->startedPhase?->sequence ?? 1)) - 1),
            'skippedPhases' => $skipped,
            'startHandling' => $this->label($job->start_handling),
            'startReason' => $job->start_reason ?? '',
            'activity' => $job->transitionEvents->map(fn ($event) => [
                'id' => $event->id,
                'title' => $this->label($event->transition_type),
                'text' => trim(collect([$event->from_value && $event->to_value ? "{$event->from_value} → {$event->to_value}" : ($event->to_value ?: null), $event->reason])->filter()->join(' · ')),
                'time' => $event->occurred_at?->diffForHumans() ?? '',
                'source' => $this->label($event->source),
                'actor' => $event->actor?->name ?? 'System',
            ])->values()->all(),
        ];
    }

    private function mapTask(FlowTask $task): array
    {
        $membership = $task->assignee?->workspaceMemberships?->first();
        $actor = request()->user();
        $authorization = app(FlowAuthorizationService::class);

        return [
            'dbId' => $task->id,
            'id' => $task->code,
            'job' => $task->job?->code,
            'title' => $task->title,
            'status' => $task->status?->name ?? 'Not Started',
            'statusId' => $task->task_status_id,
            'statusCode' => $task->status?->code,
            'createdAt' => $task->created_at?->toIso8601String(),
            'updatedAt' => $task->updated_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'assignee' => $task->assignee?->name ?? 'Unassigned',
            'assigneeId' => $task->assignee_id,
            'canUpdate' => $actor ? $authorization->canUpdateTask($actor, $task) : false,
            'canAssign' => $actor ? $authorization->canAssignExistingTask($actor, $task) : false,
            'canDelete' => $actor ? $authorization->canDeleteTask($actor, $task) : false,
            'canChangeDueDate' => $actor ? $authorization->canChangeTaskDueDate($actor, $task) : false,
            'dept' => $membership?->department?->name ?? 'Unassigned',
            'due' => $task->due_date?->toDateString() ?? '',
            'priority' => $task->priority?->name ?? 'Medium',
            'priorityId' => $task->priority_id,
            'progress' => (int) $task->progress,
            'phase' => $task->phase?->taskPack?->name ?? $task->phase?->short_name ?? 'General',
            'phaseIndex' => max(0, ((int) ($task->phase?->sequence ?? 1)) - 1),
            'phaseId' => $task->workflow_phase_id,
            'attention' => (bool) $task->attention_required,
            'blockerReason' => $task->blocker_reason ?? '',
            'checklist' => $task->checklistItems->map(fn ($item) => [
                'id' => $item->id,
                'text' => $item->label,
                'done' => (bool) $item->is_completed,
            ])->values()->all(),
            'comments' => $task->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'user' => $comment->user?->name ?? 'System',
                'text' => $comment->body,
                'time' => $comment->created_at?->diffForHumans() ?? '',
            ])->values()->all(),
        ];
    }

    private function mapDocument(FlowDocument $document): array
    {
        return [
            'dbId' => $document->id,
            'id' => $document->code,
            'name' => $document->currentVersion?->original_name ?? $document->name,
            'type' => $document->category?->name ?? 'Other',
            'categoryId' => $document->document_category_id,
            'phaseId' => $document->workflow_phase_id,
            'phase' => $document->phase?->name,
            'taskPackItemId' => $document->task_pack_item_id,
            'taskRequirement' => $document->taskPackItem?->title,
            'job' => $document->job?->code,
            'client' => $document->job?->client_id,
            'version' => 'v'.($document->currentVersion?->version ?? 1),
            'uploadedBy' => $document->uploader?->name ?? 'System',
            'date' => $document->created_at?->toDateString() ?? '',
            'final' => (bool) $document->is_final,
        ];
    }

    private function mapWorkflow(WorkflowTemplate $workflow): array
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

    private function mapTaskPack(TaskPack $taskPack): array
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

    private function mapMasterData(Collection $master): array
    {
        return collect(MasterDataType::cases())
            ->mapWithKeys(fn (MasterDataType $type): array => [
                $type->key() => $master->get($type->value, collect())->values()->all(),
            ])
            ->all();
    }

    private function authorizeClientSensitiveData(User $user, array $data, ?Client $client = null): void
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

    private function canViewFinancials(User $user): bool
    {
        $authorization = app(FlowAuthorizationService::class);

        return $authorization->allows($user, 'invoice', 'view')
            && $authorization->canViewSensitiveField($user, 'invoice_amount');
    }

    private function label(?string $value): string
    {
        return Str::of((string) $value)->replace(['_', '-'], ' ')->title()->toString();
    }

    private function initials(string $name): string
    {
        return Str::of($name)->explode(' ')->filter()->map(fn ($part) => Str::substr($part, 0, 1))->take(2)->implode('');
    }
}
