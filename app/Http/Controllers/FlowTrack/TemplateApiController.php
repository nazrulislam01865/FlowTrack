<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreClientRequest;
use App\Http\Requests\FlowTrack\StoreJobRequest;
use App\Http\Requests\FlowTrack\UpdateTaskRequest;
use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateClientAction;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\Actions\MoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Application\Presenters\CurrentUserPresenter;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
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
    public function bootstrap(Request $request, ProgressCalculator $progressCalculator, RoleMatrixService $roleMatrixService, FlowAuthorizationService $authorization, RoleMatrixCatalog $roleCatalog, CurrentUserPresenter $currentUserPresenter): JsonResponse
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
                    'phases.taskPack.items',
                    'phases.documentCategory:id,name',
                ])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
            : collect();

        $jobs = FlowJob::query()
            ->visibleTo($user)
            ->with([
                'client:id,name,code', 'workflow.phases', 'currentPhase', 'startedPhase',
                'product:id,name,code', 'productCategory:id,name,code', 'currency:id,name,code',
                'priority:id,name,code', 'owner:id,name,initials', 'coordinator:id,name,initials',
                'phaseHistory.phase:id,sequence',
            ])
            ->latest('id')
            ->get();


        $tasks = $authorization->allows($user, 'tasks', 'view')
            ? $authorization->applyTaskVisibility(FlowTask::query(), $user)
            ->with([
                'job:id,code,title,client_id,current_phase_id,workflow_template_id,progress', 'job.client:id,name',
                'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
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
            ->with(['job:id,code,client_id', 'job.client:id,name', 'category:id,name', 'uploader:id,name', 'currentVersion'])
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
                ])
                ->orderBy('name')
                ->get()
            : collect();

        $notifications = $user->notifications()->latest()->limit(50)->get()->map(function ($notification) {
            $data = $notification->data;

            return [
                'id' => $notification->id,
                'title' => $data['title'] ?? 'Notification',
                'text' => $data['text'] ?? '',
                'time' => $notification->created_at?->diffForHumans() ?? '',
                'read' => $notification->read_at !== null,
                'type' => $data['type'] ?? 'info',
                'job' => $data['job'] ?? null,
            ];
        })->values();

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
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
            'documents' => $documents->map(fn (FlowDocument $document) => $this->mapDocument($document)),
            'notifications' => $notifications,
            'workflows' => $workflows->map(fn (WorkflowTemplate $workflow) => $this->mapWorkflow($workflow)),
            'masterData' => $this->mapMasterData($master),
            'masterDataCatalog' => collect(MasterDataType::cases())->map(fn (MasterDataType $type) => $type->catalogEntry())->values()->all(),
            'taskPacks' => $taskPacks->map(fn (TaskPack $taskPack) => $this->mapTaskPack($taskPack)),
            'capabilities' => $capabilities,
            'accessControl' => $accessControl,
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

    public function destroyTask(Request $request, FlowTask $task): JsonResponse
    {
        $task->loadMissing('job');
        abort_unless($task->job?->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('tasks', 'delete', $task), 403);
        $job = $task->job;
        $task->delete();
        app(ProgressCalculator::class)->recalculateJob($job);

        return response()->json([
            'message' => 'Task deleted successfully.',
            'job' => $this->mapJob($this->loadJob($job), $this->canViewFinancials($request->user())),
        ]);
    }

    public function destroyDocument(Request $request, FlowDocument $document): JsonResponse
    {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('documents', 'delete', $document), 403);
        $document->delete();

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

        try {
            $job = $action->execute(new CreateJobData(
                workspaceId: $request->user()->currentWorkspaceId(),
                clientId: (int) $data['client_id'],
                workflowTemplateId: (int) $data['workflow_template_id'],
                startPhaseId: (int) $data['start_phase_id'],
                productId: isset($data['product_id']) ? (int) $data['product_id'] : null,
                productCategoryId: isset($data['product_category_id']) ? (int) $data['product_category_id'] : null,
                currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                priorityId: isset($data['priority_id']) ? (int) $data['priority_id'] : null,
                ownerId: isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                coordinatorId: isset($data['coordinator_id']) ? (int) $data['coordinator_id'] : null,
                title: $data['title'],
                quantity: (int) $data['quantity'],
                quotedAmount: (float) ($data['quoted_amount'] ?? 0),
                deliveryDate: $data['delivery_date'] ?? null,
                startReason: $data['start_reason'] ?? null,
                memberIds: $data['member_ids'] ?? [],
                draft: (bool) ($data['draft'] ?? false),
            ), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        }

        $job = $this->loadJob($job);
        $tasks = $this->loadJobTasks($job);

        return response()->json([
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function updateTask(
        UpdateTaskRequest $request,
        FlowTask $task,
        TaskRepository $tasks,
        AccessResolver $access,
        UpdateTaskAction $action,
    ): JsonResponse {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($access->canManageTasks($request->user(), $visibleTask->job) || $visibleTask->assignee_id === $request->user()->id, 403);

        $data = $request->validated();
        if (array_key_exists('assignee_id', $data) && (int) ($data['assignee_id'] ?? 0) !== (int) ($visibleTask->assignee_id ?? 0)) {
            abort_unless($request->user()->hasFlowAction('tasks', 'assign', $visibleTask), 403);
        }

        try {
            $updated = $action->execute($visibleTask, $data, $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['blocker_reason' => $exception->getMessage()]);
        }

        $job = $this->loadJob($updated->job);
        $updated->load(['job.client', 'phase.taskPack', 'assignee.workspaceMemberships.department', 'status', 'priority', 'checklistItems', 'comments.user']);

        return response()->json([
            'task' => $this->mapTask($updated),
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
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

        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($access->canManageJob($request->user(), $visible), 403);

        try {
            $updated = $action->execute($visible, (int) $data['phase_id'], $request->user(), $data['reason'] ?? null);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['phase_id' => $exception->getMessage()]);
        }

        $updated = $this->loadJob($updated);
        $tasks = $this->loadJobTasks($updated);

        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    private function loadJob(FlowJob $job): FlowJob
    {
        return $job->fresh([
            'client:id,name,code', 'workflow.phases', 'currentPhase', 'startedPhase',
            'product:id,name,code', 'productCategory:id,name,code', 'currency:id,name,code',
            'priority:id,name,code', 'owner:id,name,initials', 'coordinator:id,name,initials', 'phaseHistory.phase:id,sequence',
        ]);
    }

    private function loadJobTasks(FlowJob $job): Collection
    {
        return FlowTask::query()
            ->where('flow_job_id', $job->id)
            ->with([
                'job:id,code,title,client_id,current_phase_id,workflow_template_id,progress', 'job.client:id,name',
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

        return [
            'dbId' => $job->id,
            'id' => $job->code,
            'order' => $job->order_code ?? '',
            'title' => $job->title,
            'client' => $job->client_id,
            'product' => $job->product?->name ?? 'Unspecified product',
            'productId' => $job->product_id,
            'category' => $job->productCategory?->name ?? '',
            'categoryId' => $job->product_category_id,
            'qty' => (int) $job->quantity,
            'price' => $canViewFinancials ? (float) $job->quoted_amount : null,
            'currency' => $job->currency?->code ?? 'USD',
            'currencyId' => $job->currency_id,
            'phase' => max(0, ((int) ($job->currentPhase?->sequence ?? 1)) - 1),
            'phaseId' => $job->current_phase_id,
            'status' => $this->label($job->status),
            'health' => $this->label($job->health),
            'progress' => (int) $job->progress,
            'owner' => $job->owner?->name ?? 'Unassigned',
            'ownerId' => $job->owner_id,
            'coordinator' => $job->coordinator?->name ?? 'Unassigned',
            'coordinatorId' => $job->coordinator_id,
            'delivery' => $job->delivery_date?->toDateString() ?? '',
            'priority' => $job->priority?->name ?? 'Medium',
            'priorityId' => $job->priority_id,
            'next' => $job->next_action ?? 'Review current phase',
            'milestone' => $job->milestone ?? '',
            'attention' => (bool) $job->attention_required,
            'workflow' => $job->workflow_template_id,
            'createdFromPhase' => max(0, ((int) ($job->startedPhase?->sequence ?? 1)) - 1),
            'skippedPhases' => $skipped,
            'startHandling' => $this->label($job->start_handling),
            'startReason' => $job->start_reason ?? '',
            'activity' => [],
        ];
    }

    private function mapTask(FlowTask $task): array
    {
        $membership = $task->assignee?->workspaceMemberships?->first();

        return [
            'dbId' => $task->id,
            'id' => $task->code,
            'job' => $task->job?->code,
            'title' => $task->title,
            'status' => $task->status?->name ?? 'Not Started',
            'statusId' => $task->task_status_id,
            'statusCode' => $task->status?->code,
            'assignee' => $task->assignee?->name ?? 'Unassigned',
            'assigneeId' => $task->assignee_id,
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
                'approval' => (bool) $phase->requires_approval,
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
