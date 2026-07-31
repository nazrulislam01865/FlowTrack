<?php

namespace App\Http\Controllers\FlowTrack;

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
use App\Modules\OrderFlow\Data\Models\FlowActivity;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TemplateApiController extends Controller
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $jobActivityCache = [];

    public function __construct(private readonly JobDocumentRequirementService $documentRequirements) {}

    public function bootstrap(
        Request $request,
        RoleMatrixService $roleMatrixService,
        FlowAuthorizationService $authorization,
        RoleMatrixCatalog $roleCatalog,
        CurrentUserPresenter $currentUserPresenter,
        OperationalMetricsService $metrics,
        NotificationFeedService $notificationFeed,
        FlowTrackBootstrapCache $bootstrapCache,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user, 401);

        $payload = $bootstrapCache->remember(
            $user,
            fn (): array => $this->buildBootstrapPayload(
                $request,
                $roleMatrixService,
                $authorization,
                $roleCatalog,
                $currentUserPresenter,
                $metrics,
                $notificationFeed,
            ),
        );

        // Notifications and configuration are intentionally refreshed on every
        // bootstrap request. They are small, user-visible collections and must
        // never be replaced by an older cached snapshot after a save, realtime
        // event, permission update, or concurrent refresh.
        $payload = array_replace(
            $payload,
            $this->buildFreshDynamicPayload($request, $authorization, $notificationFeed),
        );

        $etag = $bootstrapCache->etag($payload);
        $requestedEtags = collect($request->getETags())
            ->map(fn (string $value): string => trim(str_replace('W/', '', $value), '"'))
            ->all();
        if (in_array($etag, $requestedEtags, true)) {
            return response()->json(null, 304)
                ->setEtag($etag)
                ->header('Cache-Control', 'private, no-cache, must-revalidate');
        }

        return response()->json($payload)
            ->setEtag($etag)
            ->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    /**
     * Return the small, rapidly changing bootstrap collections directly from
     * the database. Keeping these outside the cached core prevents an older
     * concurrent response from making Notifications, Workflow Setup, or Task
     * Pack Setup temporarily disappear.
     *
     * @return array<string, mixed>
     */
    private function buildFreshDynamicPayload(
        Request $request,
        FlowAuthorizationService $authorization,
        NotificationFeedService $notificationFeed,
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaceId = (int) $user->currentWorkspaceId();
        $canViewWorkflowSetup = $authorization->allows($user, 'workflow', 'view');
        $canCreateJobs = $authorization->allows($user, 'jobs', 'create');

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
        $notifications = $notificationFeed->latest($user, 50)->map(function ($notification) use ($authorityNotificationView): array {
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

        $recentActivities = $authorization->allows($user, 'tasks', 'view')
            ? $authorization->applyTaskVisibility(FlowTask::query(), $user)
                ->with(['job:id,code', 'creator:id,name,initials'])
                ->latest('id')
                ->limit(6)
                ->get()
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
                ->values()
            : collect();

        return [
            'notifications' => $notifications,
            'notificationAccess' => [
                'authorityView' => $authorityNotificationView,
                'statusEndpoint' => route('flowtrack.notifications.status'),
            ],
            'activities' => $recentActivities,
            'workflows' => $workflows->map(fn (WorkflowTemplate $workflow) => $this->mapWorkflow($workflow)),
            'taskPacks' => $taskPacks->map(fn (TaskPack $taskPack) => $this->mapTaskPack($taskPack)),
        ];
    }

    private function buildBootstrapPayload(
        Request $request,
        RoleMatrixService $roleMatrixService,
        FlowAuthorizationService $authorization,
        RoleMatrixCatalog $roleCatalog,
        CurrentUserPresenter $currentUserPresenter,
        OperationalMetricsService $metrics,
        NotificationFeedService $notificationFeed,
    ): array {
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

        // Operational collections are loaded through paginated segment endpoints.
        $clients = collect();

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


        $jobs = collect();
        $tasks = collect();
        $documents = collect();

        $boardJobIds = $authorization->applyJobBoardVisibility(FlowJob::query(), $user)
            ->where('status', '!=', 'completed')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        // Bootstrap is read-only and intentionally excludes operational record bodies.
        // Clients, Jobs, Tasks and documents are loaded in bounded pages below.

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


        $recentActivities = $authorization->allows($user, 'tasks', 'view')
            ? $authorization->applyTaskVisibility(FlowTask::query(), $user)
                ->with(['job:id,code', 'creator:id,name,initials'])
                ->latest('id')
                ->limit(6)
                ->get()
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
                ->values()
            : collect();

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

        return [
            'currentUser' => $currentUserPresenter->toArray($user),
            'segments' => [
                'clients' => route('flowtrack.template-api.bootstrap.segment', ['segment' => 'clients'], false),
                'jobs' => route('flowtrack.template-api.bootstrap.segment', ['segment' => 'jobs'], false),
                'tasks' => route('flowtrack.template-api.bootstrap.segment', ['segment' => 'tasks'], false),
                'documents' => route('flowtrack.template-api.bootstrap.segment', ['segment' => 'documents'], false),
                'perPage' => max(25, min(250, (int) config('flowtrack.bootstrap_segment_per_page', 100))),
            ],
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
        ];
    
    }

    public function bootstrapSegment(
        Request $request,
        string $segment,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaceId = (int) $user->currentWorkspaceId();
        $perPage = max(25, min(250, (int) $request->integer(
            'per_page',
            (int) config('flowtrack.bootstrap_segment_per_page', 100),
        )));
        $canViewFinancials = $authorization->allows($user, 'invoice', 'view')
            && $authorization->canViewSensitiveField($user, 'invoice_amount');
        $canViewClientContacts = $authorization->canViewSensitiveField($user, 'client_contact_details');

        $paginator = match ($segment) {
            'clients' => $authorization->allows($user, 'clients', 'view')
                ? $authorization->applyClientVisibility(Client::query(), $user)
                    ->where('status', 'active')
                    ->with('accountManager:id,name,initials')
                    ->withCount([
                        'jobs as active_jobs_count' => fn (Builder $query) => $authorization->applyJobVisibility($query, $user)->where('status', '!=', 'completed'),
                        'jobs as attention_jobs_count' => fn (Builder $query) => $authorization->applyJobVisibility($query, $user)->where('attention_required', true),
                    ])
                    ->orderBy('name')
                    ->orderBy('id')
                    ->paginate($perPage)
                : Client::query()->whereRaw('1 = 0')->paginate($perPage),
            'jobs' => $authorization->allows($user, 'jobs', 'view')
                ? FlowJob::query()
                    ->visibleTo($user)
                    ->with([
                    'client:id,name,code', 'workflow.phases', 'currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory', 'startedPhase',
                    'product:id,name,code', 'productCategory:id,name,code', 'items.product:id,name,code', 'items.productCategory:id,name,code', 'currency:id,name,code',
                    'priority:id,name,code', 'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
                    'members.user:id,name,initials', 'excludedPhases.phase:id,name,short_name,sequence',
                    'phaseHistory.phase:id,name,short_name,sequence', 'phaseHistory.changedBy:id,name,initials',
                    'transitionEvents.actor:id,name,initials', 'transitionEvents.phase:id,name,short_name', 'transitionEvents.task:id,code,title',
                    ])
                    ->latest('id')
                    ->paginate($perPage)
                : FlowJob::query()->whereRaw('1 = 0')->paginate($perPage),
            'tasks' => $authorization->allows($user, 'tasks', 'view')
                ? $authorization->applyTaskVisibility(FlowTask::query(), $user)
                    ->with([
                        'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id', 'job.client:id,name',
                        'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                        'job.documents.category:id,name', 'job.documents.uploader:id,name,initials', 'job.documents.versions.uploader:id,name,initials',
                        'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
                        'packItem:id,task_pack_id,title,document_category_id,is_required', 'packItem.documentCategory:id,name',
                        'documentCategory:id,name', 'creator:id,name,initials',
                        'assignee:id,name,initials', 'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $workspaceId)->with('department:id,name'),
                        'status:id,name,code', 'priority:id,name,code', 'checklistItems', 'comments.user:id,name,initials',
                        'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
                    ])
                    ->orderByRaw('due_date is null')
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->paginate($perPage)
                : FlowTask::query()->whereRaw('1 = 0')->paginate($perPage),
            'documents' => $authorization->allows($user, 'documents', 'view')
                ? $authorization->applyDocumentVisibility(FlowDocument::query(), $user)
                    ->with(['job:id,code,client_id,current_phase_id', 'job.client:id,name', 'phase:id,name,short_name,sequence', 'taskPackItem:id,title', 'category:id,name', 'uploader:id,name', 'currentVersion'])
                    ->latest('id')
                    ->paginate($perPage)
                : FlowDocument::query()->whereRaw('1 = 0')->paginate($perPage),
            default => abort(404),
        };

        if ($segment === 'jobs') {
            $this->primeJobActivityCache(collect($paginator->items()));
        }

        $mapped = collect($paginator->items())->map(fn ($record) => match ($segment) {
            'clients' => $this->mapClient($record, $canViewFinancials, $canViewClientContacts),
            'jobs' => $this->mapJob($record, $canViewFinancials),
            'tasks' => $this->mapTask($record),
            'documents' => $this->mapDocument($record),
        })->values();

        return response()->json([
            'segment' => $segment,
            'data' => $mapped,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'nextPageUrl' => $paginator->nextPageUrl(),
            ],
        ])->header('Cache-Control', 'private, no-cache, must-revalidate');
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

    public function updateClient(StoreClientRequest $request, Client $client, UpdateClientAction $action): JsonResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validated();
        $this->authorizeClientSensitiveData($request->user(), $data, $client);
        if (array_key_exists('account_manager_id', $data) && (int) ($data['account_manager_id'] ?? 0) !== (int) ($client->account_manager_id ?? 0)) {
            abort_unless($request->user()->hasFlowAction('clients', 'assign', $client), 403);
        }
        $client = $action->execute($client, $data);
        $client->load('accountManager:id,name,initials')->loadCount([
            'jobs as active_jobs_count' => fn (Builder $query) => $query->where('status', '!=', 'completed'),
            'jobs as attention_jobs_count' => fn (Builder $query) => $query->where('attention_required', true),
        ]);

        return response()->json(['client' => $this->mapClient($client, $this->canViewFinancials($request->user()), app(FlowAuthorizationService::class)->canViewSensitiveField($request->user(), 'client_contact_details'))]);
    }

    public function destroyClient(Request $request, Client $client, DeleteClientAction $action): JsonResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('clients', 'delete', $client), 403);

        $action->execute($client);

        return response()->json(['message' => 'Client, related Jobs, Tasks, documents and stored files were deleted successfully.']);
    }

    public function destroyJob(Request $request, FlowJob $job, DeleteJobAction $action): JsonResponse
    {
        abort_unless($job->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('jobs', 'delete', $job), 403);

        $action->execute($job);

        return response()->json(['message' => 'Job, Tasks, documents and stored files were deleted successfully.']);
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
            true,
            $request->user(),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            $data['notes'] ?? null,
            (int) $data['workflow_phase_id'],
            isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
            false,
            isset($data['task_id']) ? (int) $data['task_id'] : null,
        );

        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($job);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        $workflowResult = $action->workflowResult();
        $message = 'Document uploaded and linked to the required Task.';
        if ($workflowResult['moved'] ?? false) {
            $message = 'Document uploaded, the required Task was completed, and the Job moved to the next Workflow Phase.';
        }

        return response()->json([
            'message' => $message,
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function destroyDocument(
        Request $request,
        FlowDocument $document,
        WorkflowAutomationService $automation,
        DeleteDocumentAction $action,
    ): JsonResponse {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('documents', 'delete', $document), 403);

        $job = $action->execute($document, $request->user());
        if ($job) {
            $automation->synchronize($job->fresh(), $request->user(), 'document_deletion', false);
        }

        return response()->json(['message' => 'Document and all stored versions deleted successfully.']);
    }

    public function storeJob(
        StoreJobRequest $request,
        CreateJobAction $action,
        UploadJobAttachmentAction $attachmentAction,
    ): JsonResponse
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

        $startPhase = WorkflowPhase::query()
            ->where('workflow_template_id', (int) $data['workflow_template_id'])
            ->where('is_active', true)
            ->where('allow_job_start', true)
            ->whereKey((int) $data['start_phase_id'])
            ->first();

        if (! $startPhase) {
            throw ValidationException::withMessages([
                'start_phase_id' => 'The selected starting phase is not available for this Workflow Template.',
            ]);
        }

        $attachmentFiles = collect($request->file('attachments', []))->filter();
        $startReason = $data['start_reason'] ?? null;
        if ($startPhase->sequence > 1 && blank($startReason)) {
            $startReason = $attachmentFiles->isNotEmpty()
                ? 'Job started directly at this Workflow Phase with supporting attachment(s).'
                : 'Job started directly at the selected Workflow Phase.';
        }

        $storedFiles = [];
        $attachmentUploadStarted = false;

        try {
            $job = DB::transaction(function () use (
                $request,
                $data,
                $productItems,
                $primaryProduct,
                $submittedQuantity,
                $startReason,
                $attachmentFiles,
                $action,
                $attachmentAction,
                &$storedFiles,
                &$attachmentUploadStarted,
            ): FlowJob {
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
                    startReason: $startReason,
                    memberIds: $data['member_ids'] ?? [],
                    draft: (bool) ($data['draft'] ?? false),
                    productItems: $productItems->all(),
                ), $request->user());

                $attachmentUploadStarted = $attachmentFiles->isNotEmpty();
                foreach ($attachmentFiles as $attachment) {
                    $document = $attachmentAction->execute($job, $attachment, $request->user());
                    $version = $document->versions->first();
                    if ($version) {
                        $storedFiles[] = ['disk' => $version->disk, 'path' => $version->path];
                    }
                }

                return $job;
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                Storage::disk($storedFile['disk'])->delete($storedFile['path']);
            }

            if (! $attachmentUploadStarted) {
                throw $exception;
            }

            report($exception);
            throw ValidationException::withMessages([
                'attachments' => 'The Job could not be created because one or more attachments could not be saved. Please try again.',
            ]);
        }

        $job = $this->loadJob($job);
        $tasks = $this->loadJobTasks($job, $request->user());

        return response()->json([
            'message' => $attachmentFiles->isEmpty()
                ? "{$job->code} created successfully."
                : "{$job->code} created successfully with {$attachmentFiles->count()} attachment(s).",
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

    public function jobTasks(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'view', $visible), 403);

        return response()->json([
            'job' => $this->mapJob($this->loadJob($visible), $this->canViewFinancials($request->user())),
            'tasks' => $this->loadJobTasks($visible, $request->user())->map(fn (FlowTask $task) => $this->mapTask($task)),
        ])->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    public function storeTask(
        StoreTaskRequest $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        CreateTaskAction $action,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'create', $visible), 403);

        try {
            $created = $action->execute($visible, $request->validated(), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        $updatedJob = $this->loadJob($created->job);
        $jobTasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => "Task {$created->code} created successfully.",
            'task' => $this->mapTask($created->loadMissing([
                'job.client', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                'phase.taskPack', 'packItem.documentCategory', 'documentCategory', 'assignee.workspaceMemberships.department',
                'status', 'priority', 'checklistItems', 'comments.user', 'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
            ])),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $jobTasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
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
        $updated->load([
            'job.client', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
            'phase.taskPack', 'assignee.workspaceMemberships.department', 'status', 'priority', 'checklistItems', 'comments.user',
            'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
        ]);

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
            'phase_id' => [
                'required',
                'integer',
                Rule::exists('workflow_phases', 'id')->where(
                    fn ($query) => $query
                        ->where('workflow_template_id', $job->workflow_template_id)
                        ->where('is_active', true),
                ),
            ],
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
        $jobs->findVisible($request->user(), $job->id);

        return response()->json([
            'message' => 'Workflow phases cannot be removed from a Job. Update the phase in Workflow Setup instead.',
        ], 403);
    }

    private function loadJob(FlowJob $job): FlowJob
    {
        return $job->fresh([
            'client:id,name,code', 'workflow.phases', 'currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory', 'startedPhase',
            'product:id,name,code', 'productCategory:id,name,code', 'items.product:id,name,code', 'items.productCategory:id,name,code', 'currency:id,name,code',
            'priority:id,name,code', 'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
            'members.user:id,name,initials', 'excludedPhases.phase:id,name,short_name,sequence', 'phaseHistory.phase:id,name,short_name,sequence', 'phaseHistory.changedBy:id,name,initials',
            'transitionEvents.actor:id,name,initials', 'transitionEvents.phase:id,name,short_name', 'transitionEvents.task:id,code,title',
        ]);
    }

    private function loadJobTasks(FlowJob $job, User $user): Collection
    {
        return app(FlowAuthorizationService::class)
            ->applyTaskVisibility(FlowTask::query(), $user)
            ->where('flow_job_id', $job->id)
            ->with([
                'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id', 'job.client:id,name',
                'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                'job.documents.category:id,name', 'job.documents.uploader:id,name,initials', 'job.documents.versions.uploader:id,name,initials',
                'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
                'packItem:id,task_pack_id,title,document_category_id,is_required', 'packItem.documentCategory:id,name',
                'documentCategory:id,name',
                'assignee:id,name,initials', 'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $job->workspace_id)->with('department:id,name'),
                'status:id,name,code', 'priority:id,name,code', 'checklistItems', 'comments.user:id,name,initials',
                'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
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
                    'notes' => $history->notes ?? '',
                    'readinessDetails' => $history->readiness_details ?? [],
                ])
                ->sortBy('phaseIndex')
                ->values()
                ->all(),
            'documentRequirements' => $this->documentRequirements->requirements($job)->values()->all(),
            'workflow' => $job->workflow_template_id,
            'createdFromPhase' => max(0, ((int) ($job->startedPhase?->sequence ?? 1)) - 1),
            'skippedPhases' => $skipped,
            'startHandling' => $this->label($job->start_handling),
            'startReason' => $job->start_reason ?? '',
            'activity' => $this->jobActivity($job),
        ];
    }

    private function mapTask(FlowTask $task): array
    {
        $task->loadMissing(['packItem.documentCategory', 'documentCategory']);
        $membership = $task->assignee?->workspaceMemberships?->first();
        $actor = request()->user();
        $authorization = app(FlowAuthorizationService::class);
        $documentCategory = $task->documentCategory ?: $task->packItem?->documentCategory;
        $documentSource = $task->document_requirement_source
            ?: ($task->packItem?->document_category_id ? 'task_pack_item' : null);
        $isConfiguredDocumentTask = $documentCategory && in_array($documentSource, ['workflow_phase', 'task_pack_item'], true);
        $isAttachmentTask = $isConfiguredDocumentTask || $this->looksLikeDocumentTask($task);

        if (! $documentCategory && $isAttachmentTask) {
            $documentCategory = $this->inferDocumentCategory($task);
            $documentSource = $documentCategory ? 'task_attachment' : null;
        }

        $canViewDocuments = (bool) ($actor && $isAttachmentTask
            && $authorization->allows($actor, 'documents', 'view', $task->job));
        $canUploadDocument = (bool) ($actor && $isAttachmentTask && $documentCategory
            && (int) $task->workflow_phase_id === (int) $task->job?->current_phase_id
            && $authorization->allows($actor, 'documents', 'create', $task->job)
            && $authorization->allows($actor, 'documents', 'link', $task->job));

        $documentRows = collect();
        if ($canViewDocuments) {
            $task->loadMissing([
                'job.documents.category:id,name',
                'job.documents.uploader:id,name,initials',
                'job.documents.versions.uploader:id,name,initials',
            ]);

            $documentRows = ($task->job?->documents ?? collect())
                ->filter(function (FlowDocument $document) use ($task, $documentSource, $documentCategory, $isConfiguredDocumentTask): bool {
                    if ((int) $document->workflow_phase_id !== (int) $task->workflow_phase_id) {
                        return false;
                    }

                    if ($isConfiguredDocumentTask && (int) $document->document_category_id !== (int) $documentCategory?->id) {
                        return false;
                    }

                    if ($documentSource === 'task_pack_item' || $documentSource === 'task_attachment') {
                        return (int) ($document->task_pack_item_id ?? 0) === (int) ($task->task_pack_item_id ?? 0);
                    }

                    return $document->task_pack_item_id === null;
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
            $task->assignee,
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
            ?? ($task->completed_at ? ($task->assignee?->name ?? 'System') : null);

        return [
            'dbId' => $task->id,
            'id' => $task->code,
            'job' => $task->job?->code,
            'jobDbId' => $task->flow_job_id,
            'jobTitle' => $task->job?->title,
            'title' => $task->title,
            'description' => $task->description ?? '',
            'status' => $task->status?->name ?? 'Not Started',
            'statusId' => $task->task_status_id,
            'statusCode' => $task->status?->code,
            'createdAt' => $task->created_at?->toIso8601String(),
            'updatedAt' => $task->updated_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'completedBy' => $completedByName,
            'completedByInitials' => $completedByName ? $this->initials($completedByName) : null,
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
            'isDocumentTask' => (bool) $isAttachmentTask,
            'documentRequirement' => ($isAttachmentTask && $documentCategory) ? [
                'key' => $documentSource === 'task_pack_item'
                    ? "task:{$task->task_pack_item_id}:{$documentCategory->id}"
                    : ($documentSource === 'task_attachment'
                        ? "task-attachment:{$task->id}:{$documentCategory->id}"
                        : "phase:{$task->workflow_phase_id}:{$documentCategory->id}"),
                'source' => $documentSource,
                'sourceLabel' => $documentSource === 'task_pack_item'
                    ? 'Task: '.($task->packItem?->title ?? $task->title)
                    : ($documentSource === 'task_attachment'
                        ? 'Task attachment: '.$task->title
                        : 'Workflow Phase: '.($task->phase?->name ?? 'Current Phase')),
                'phaseId' => (int) $task->workflow_phase_id,
                'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
                'taskId' => (int) $task->id,
                'categoryId' => (int) $documentCategory->id,
                'categoryName' => $documentCategory->name,
                'genericAttachment' => $documentSource === 'task_attachment',
            ] : null,
            'documents' => $documentRows->all(),
            'canUploadDocument' => $canUploadDocument,
            'mentionableUsers' => $mentionableUsers->all(),
            'history' => $history->all(),
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

    private function looksLikeDocumentTask(FlowTask $task): bool
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $task->title,
            $task->description,
            $task->phase?->name,
            $task->phase?->short_name,
        ])));

        return Str::contains($haystack, [
            'quotation', 'quote', 'artwork', 'proof', 'sample', 'invoice', 'document',
            'attachment', 'file', 'purchase order', 'packing list', 'shipment document',
            'certificate', 'specification', 'requirement', 'approval sheet',
        ]);
    }

    private function inferDocumentCategory(FlowTask $task): ?MasterRecord
    {
        static $workspaceCategories = [];

        $workspaceId = (int) ($task->job?->workspace_id ?? 0);
        if (! $workspaceId) {
            return null;
        }

        if (! array_key_exists($workspaceId, $workspaceCategories)) {
            $workspaceCategories[$workspaceId] = MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', MasterDataType::DocumentCategory->value)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'workspace_id', 'name', 'code', 'type', 'status']);
        }

        $categories = $workspaceCategories[$workspaceId];
        if ($categories->isEmpty()) {
            return null;
        }

        $taskText = Str::lower(implode(' ', array_filter([
            $task->title,
            $task->description,
            $task->phase?->name,
            $task->phase?->short_name,
        ])));

        $keywordGroups = [
            ['quotation', 'quote'],
            ['artwork', 'proof'],
            ['sample'],
            ['invoice'],
            ['purchase order', 'order'],
            ['packing', 'shipment'],
            ['specification', 'requirement'],
            ['certificate'],
        ];

        foreach ($keywordGroups as $keywords) {
            if (! Str::contains($taskText, $keywords)) {
                continue;
            }

            $match = $categories->first(function (MasterRecord $category) use ($keywords): bool {
                $categoryText = Str::lower($category->name.' '.$category->code);
                return Str::contains($categoryText, $keywords);
            });

            if ($match) {
                return $match;
            }
        }

        return $categories->first();
    }

    private function mapDocument(FlowDocument $document): array
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
            'taskRequirement' => $document->taskPackItem?->title,
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


    /**
     * Return one chronological audit stream for the Job, including direct Job
     * updates and every related Task/document add, edit and delete event.
     */
    private function jobActivity(FlowJob $job): array
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
    private function primeJobActivityCache(Collection $jobs): void
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

    private function mapJobActivityRow(FlowActivity $activity): array
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

        return [
            'id' => 'activity-'.$activity->id,
            'title' => $this->activityTitle((string) $activity->event),
            'text' => trim(collect([$activity->description, $changes ?: null])->filter()->join(' · ')),
            'time' => $activity->created_at?->diffForHumans() ?? '',
            'source' => $this->label((string) ($properties['source'] ?? 'activity log')),
            'actor' => $activity->user?->name ?? 'System',
            'occurredAt' => $activity->created_at?->toIso8601String(),
            '_timestamp' => $activity->created_at?->getTimestamp() ?? 0,
        ];
    }

    private function transitionRepresentedByActivity($event, Collection $activities): bool
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

    private function activityTitle(string $event): string
    {
        return Str::of($event)
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();
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
