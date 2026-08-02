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

        // Page-specific configuration is loaded lazily through pageContext().

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
     * Board assignee filter members. Admin and Super Admin receive all active
     * workspace users; regular users receive only their own account. This is
     * kept fresh outside the cached bootstrap so role changes and newly added
     * members appear in the Board filter immediately after optimize/cache clear.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function boardAssigneeUsers(User $user, FlowAuthorizationService $authorization): Collection
    {
        $workspaceId = (int) $user->currentWorkspaceId();

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->when(! $authorization->isAdministrator($user), fn (Builder $query) => $query->where('user_id', $user->id))
            ->with(['user:id,name,initials,email,locale', 'role:id,name', 'department:id,name'])
            ->get()
            ->filter(fn (WorkspaceMembership $membership): bool => $membership->user !== null)
            ->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'initials' => $membership->user->initials ?: $this->initials($membership->user->name),
                'email' => $authorization->isAdministrator($user) ? $membership->user->email : null,
                'role' => $membership->role?->name ?? 'Member',
                'dept' => $membership->department?->name ?? 'Unassigned',
                'language' => $membership->user->locale ?: 'en',
            ])
            ->values();
    }

    /**
     * Active workspace users for assignment lists and Access Control. This is
     * intentionally reusable by the cached and fresh bootstrap payloads so an
     * admin-created user appears immediately after the save request.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function workspaceUsers(User $user, FlowAuthorizationService $authorization, int $workspaceId, bool $canViewUsers): Collection
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->when(! $authorization->isAdministrator($user), fn (Builder $query) => $query->where('user_id', $user->id))
            ->with(['user:id,name,initials,email,locale', 'role:id,name', 'department:id,name'])
            ->get()
            ->filter(fn (WorkspaceMembership $membership): bool => $membership->user !== null)
            ->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'initials' => $membership->user->initials ?: $this->initials($membership->user->name),
                'email' => $canViewUsers ? $membership->user->email : null,
                'role' => $membership->role?->name ?? 'Member',
                'dept' => $membership->department?->name ?? 'Unassigned',
                'language' => $membership->user->locale ?: 'en',
            ])
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, array<string, mixed>>>
     */
    private function workspaceMasterRecords(int $workspaceId, bool $canViewInactive): Collection
    {
        return MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->when(! $canViewInactive, fn (Builder $query) => $query->where('status', 'active'))
            ->with('parent:id,code,name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('type')
            ->map(fn (Collection $records) => $records->map(fn (MasterRecord $record): array => [
                'id' => $record->id,
                'code' => $record->code,
                'name' => $record->name,
                'detail' => $record->description ?? '',
                'description' => $record->description ?? '',
                'status' => Str::title($record->status),
                'parent_id' => $record->parent_id,
                'parent_name' => $record->parent?->name,
                'parent_code' => $record->parent?->code,
                'sort_order' => (int) $record->sort_order,
            ])->values());
    }

    /**
     * @return \Illuminate\Support\Collection<string, array<string, mixed>>
     */
    private function capabilitiesPayload(User $user, RoleMatrixCatalog $roleCatalog, FlowAuthorizationService $authorization): Collection
    {
        return collect($roleCatalog->moduleCodes())->mapWithKeys(function (string $module) use ($user, $roleCatalog, $authorization): array {
            return [$module => [
                'scope' => $authorization->scope($user, $module),
                'actions' => array_values(array_filter(
                    $roleCatalog->actionCodes(),
                    fn (string $action): bool => $authorization->allows($user, $module, $action),
                )),
            ]];
        });
    }

    /**
     * Return only the configuration required by the page the user actually
     * opened. Operational records are delivered separately through paginated
     * list endpoints, so opening one screen never hydrates the whole workspace.
     *
     * @return array<string, mixed>
     */
    private function buildPageContextPayload(
        Request $request,
        string $page,
        FlowAuthorizationService $authorization,
        RoleMatrixService $roleMatrixService,
        OperationalMetricsService $metrics,
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaceId = (int) $user->currentWorkspaceId();
        $page = strtolower(trim($page));
        $canViewWorkflowSetup = $authorization->allows($user, 'workflow', 'view');
        $canCreateJobs = $authorization->allows($user, 'jobs', 'create');
        $canViewUsers = $user->hasFlowAction('users', 'view') || $user->isFlowAdministrator();
        $canViewMasterData = $authorization->allows($user, 'masterdata', 'view');

        $needsUsers = in_array($page, [
            'jobs', 'clients', 'board', 'mywork', 'documents',
            'workflowsetup', 'taskpacksetup', 'masterdata', 'setup',
        ], true);
        $needsBoardUsers = in_array($page, ['board', 'mywork'], true);
        $needsWorkflows = in_array($page, ['jobs', 'board', 'workflowsetup', 'taskpacksetup'], true);
        $needsMasterData = in_array($page, ['jobs', 'board', 'documents', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup'], true);
        $needsTaskPacks = in_array($page, ['workflowsetup', 'taskpacksetup'], true);
        $needsAccessControl = $page === 'setup';
        $needsMetrics = in_array($page, ['dashboard', 'mywork', 'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup'], true);
        $needsActivities = $page === 'dashboard';
        $needsClientOptions = in_array($page, ['jobs', 'documents'], true);

        $payload = ['page' => $page];

        if ($needsUsers) {
            $payload['users'] = $this->workspaceUsers($user, $authorization, $workspaceId, $canViewUsers);
        }

        if ($needsBoardUsers) {
            $payload['boardAssigneeUsers'] = $this->boardAssigneeUsers($user, $authorization);
        }

        if ($needsClientOptions && $authorization->allows($user, 'clients', 'view')) {
            $payload['clientOptions'] = $authorization->applyClientVisibility(Client::query(), $user)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'dbId' => $client->id,
                    'name' => $client->name,
                    'code' => $client->code,
                    '_lookupOnly' => true,
                    '_detailLoaded' => false,
                ])
                ->values();
        }

        if ($needsWorkflows && ($canViewWorkflowSetup || $canCreateJobs)) {
            $workflows = WorkflowTemplate::query()
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
                ->get();

            $payload['workflows'] = $workflows->map(fn (WorkflowTemplate $workflow) => $this->mapWorkflow($workflow));
        }

        if ($needsMasterData) {
            $canUseOperationalMasterData = $canViewMasterData
                || $canCreateJobs
                || $authorization->allows($user, 'tasks', 'create')
                || $authorization->allows($user, 'documents', 'create')
                || $canViewWorkflowSetup;

            $master = $canUseOperationalMasterData
                ? $this->workspaceMasterRecords($workspaceId, $canViewMasterData)
                : collect();

            $payload['masterData'] = $this->mapMasterData($master);
            $payload['masterDataCatalog'] = collect(MasterDataType::cases())
                ->map(fn (MasterDataType $type) => $type->catalogEntry())
                ->values()
                ->all();
        }

        if ($needsTaskPacks && $canViewWorkflowSetup) {
            $taskPacks = TaskPack::query()
                ->where('workspace_id', $workspaceId)
                ->with([
                    'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                    'items.defaultAssignee:id,name,initials',
                    'items.defaultDepartment:id,name,code',
                    'items.priority:id,name,code',
                    'items.documentCategory:id,name,code',
                ])
                ->orderBy('name')
                ->get();

            $payload['taskPacks'] = $taskPacks->map(fn (TaskPack $taskPack) => $this->mapTaskPack($taskPack));
        }

        if ($needsAccessControl) {
            $payload['accessControl'] = $canViewUsers
                ? [
                    ...$roleMatrixService->accessPayload($workspaceId),
                    'canManage' => $user->hasFlowAction('users', 'manage') || $user->isFlowAdministrator(),
                    'canCreateUsers' => $user->isFlowAdministrator(),
                    'canDeleteUsers' => $user->isFlowAdministrator(),
                    'isAdmin' => $user->isFlowAdministrator(),
                    'isSuperAdmin' => $user->isFlowSuperAdmin(),
                ]
                : null;
            $payload['notificationRules'] = config('flowtrack.notification_rules', []);
        }

        if ($needsMetrics) {
            $payload['summaryMetrics'] = $metrics->forPage($user, $page);
        }

        if ($needsActivities && $authorization->allows($user, 'tasks', 'view')) {
            $payload['activities'] = $authorization->applyTaskVisibility(FlowTask::query(), $user)
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
                ->values();
        }

        if ($page === 'board') {
            $payload['boardJobIds'] = $authorization->applyJobBoardVisibility(FlowJob::query(), $user)
                ->where('status', '!=', 'completed')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();
        }

        return $payload;
    }

    public function pageContext(
        Request $request,
        string $page,
        FlowAuthorizationService $authorization,
        RoleMatrixService $roleMatrixService,
        OperationalMetricsService $metrics,
    ): JsonResponse {
        $allowedPages = [
            'dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents', 'reports',
            'notifications', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'profile',
        ];
        abort_unless(in_array($page, $allowedPages, true), 404);

        return response()->json($this->buildPageContextPayload(
            $request,
            $page,
            $authorization,
            $roleMatrixService,
            $metrics,
        ))->header('Cache-Control', 'private, no-cache, must-revalidate');
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
        abort_unless($user, 401);

        $workspaceId = (int) $user->currentWorkspaceId();
        $authorityNotificationView = $notificationFeed->isAuthority($user);
        $notifications = $notificationFeed->latest($user, 20)->map(function ($notification) use ($authorityNotificationView): array {
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

        // Keep the initial bootstrap intentionally small. All page configuration
        // and operational rows are lazy-loaded after the route is known.
        return [
            'currentUser' => $currentUserPresenter->toArray($user),
            'segments' => [
                'clients' => route('flowtrack.template-api.lists.index', ['segment' => 'clients'], false),
                'jobs' => route('flowtrack.template-api.lists.index', ['segment' => 'jobs'], false),
                'tasks' => route('flowtrack.template-api.lists.index', ['segment' => 'tasks'], false),
                'documents' => route('flowtrack.template-api.lists.index', ['segment' => 'documents'], false),
                'perPage' => max(10, min(50, (int) config('flowtrack.list_per_page', 25))),
            ],
            'contextBase' => '/flowtrack/template-api/context',
            'users' => [],
            'boardAssigneeUsers' => [],
            'clients' => [],
            'jobs' => [],
            'boardJobIds' => [],
            'boardAccess' => [
                'isAdministrator' => $authorization->isAdministrator($user),
                'taskVisibility' => $authorization->isAdministrator($user) ? 'workspace' : 'assigned_job_tasks',
            ],
            'tasks' => [],
            'documents' => [],
            'notifications' => $notifications,
            'notificationAccess' => [
                'authorityView' => $authorityNotificationView,
                'statusEndpoint' => route('flowtrack.notifications.status'),
            ],
            'activities' => [],
            'workflows' => [],
            'masterData' => [],
            'masterDataCatalog' => [],
            'taskPacks' => [],
            'capabilities' => $this->capabilitiesPayload($user, $roleCatalog, $authorization),
            'accessControl' => null,
            'summaryMetrics' => [],
            'notificationRules' => [],
        ];
    }

    /**
     * Backward-compatible alias for older cached clients.
     */
    public function bootstrapSegment(
        Request $request,
        string $segment,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        return $this->listSegment($request, $segment, $authorization);
    }

    /**
     * Lightweight paginated operational lists. Detail-only relationships are
     * intentionally excluded and fetched by the dedicated show endpoints.
     */
    public function listSegment(
        Request $request,
        string $segment,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user, 401);

        $workspaceId = (int) $user->currentWorkspaceId();
        $perPage = max(10, min(50, (int) $request->integer(
            'per_page',
            (int) config('flowtrack.list_per_page', 25),
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
                    ->simplePaginate($perPage)
                : Client::query()->whereRaw('1 = 0')->simplePaginate($perPage),
            'jobs' => $authorization->allows($user, 'jobs', 'view')
                ? FlowJob::query()
                    ->visibleTo($user)
                    ->with([
                        'client:id,name,code', 'currentPhase:id,name,short_name,sequence', 'startedPhase:id,name,short_name,sequence',
                        'product:id,name,code', 'productCategory:id,name,code',
                        'items.product:id,name,code', 'items.productCategory:id,name,code',
                        'currency:id,name,code', 'priority:id,name,code',
                        'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
                        'members.user:id,name,initials', 'workflow:id,name',
                    ])
                    ->latest('id')
                    ->simplePaginate($perPage)
                : FlowJob::query()->whereRaw('1 = 0')->simplePaginate($perPage),
            'tasks' => $authorization->allows($user, 'tasks', 'view')
                ? $authorization->applyTaskVisibility(FlowTask::query(), $user)
                    ->with([
                        'job:id,workspace_id,code,title,client_id,current_phase_id,workflow_template_id,progress,created_by,owner_id,coordinator_id,health,health_reason,delivery_date,status,attention_required',
                        'job.client:id,name', 'job.members:id,flow_job_id,user_id,can_manage_tasks',
                        'phase:id,name,short_name,sequence,task_pack_id', 'phase.taskPack:id,name',
                        'packItem:id,task_pack_id,title,document_category_id,is_required', 'packItem.documentCategory:id,name',
                        'documentCategory:id,name', 'assignee:id,name,initials', 'setupAssignee:id,name,initials',
                        'setupAssignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $workspaceId)->with('department:id,name'),
                        'assignee.workspaceMemberships' => fn ($query) => $query->where('workspace_id', $workspaceId)->with('department:id,name'),
                        'status:id,name,code', 'priority:id,name,code',
                    ])
                    ->orderByRaw('due_date is null')
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->simplePaginate($perPage)
                : FlowTask::query()->whereRaw('1 = 0')->simplePaginate($perPage),
            'documents' => $authorization->allows($user, 'documents', 'view')
                ? $authorization->applyDocumentVisibility(FlowDocument::query(), $user)
                    ->with([
                        'job:id,code,client_id,current_phase_id', 'job.client:id,name',
                        'phase:id,name,short_name,sequence', 'taskPackItem:id,title', 'task:id,code,title',
                        'category:id,name', 'uploader:id,name', 'currentVersion',
                    ])
                    ->latest('id')
                    ->simplePaginate($perPage)
                : FlowDocument::query()->whereRaw('1 = 0')->simplePaginate($perPage),
            default => abort(404),
        };

        $records = collect($paginator->items());
        $mapped = $records->map(fn ($record) => match ($segment) {
            'clients' => [...$this->mapClient($record, $canViewFinancials, $canViewClientContacts), '_detailLoaded' => false],
            'jobs' => $this->mapJobList($record, $canViewFinancials),
            'tasks' => $this->mapTaskList($record),
            'documents' => [...$this->mapDocument($record), '_detailLoaded' => false],
        })->values();

        // Lists are paginated independently. Return only the bounded related
        // Job/Client lookups needed by the current page so Board grouping and
        // labels stay complete without loading the full workspace dataset.
        $relatedJobIds = (match ($segment) {
            'tasks', 'documents' => $records->pluck('flow_job_id'),
            default => collect(),
        })->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        $relatedJobModels = $relatedJobIds->isEmpty()
            ? collect()
            : FlowJob::query()
                ->visibleTo($user)
                ->whereKey($relatedJobIds)
                ->with([
                    'client:id,name,code', 'currentPhase:id,name,short_name,sequence', 'startedPhase:id,name,short_name,sequence',
                    'product:id,name,code', 'productCategory:id,name,code',
                    'items.product:id,name,code', 'items.productCategory:id,name,code',
                    'currency:id,name,code', 'priority:id,name,code',
                    'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
                    'members.user:id,name,initials', 'workflow:id,name',
                ])
                ->get();

        $relatedClientIds = (match ($segment) {
            'jobs' => $records->pluck('client_id'),
            'tasks', 'documents' => $relatedJobModels->pluck('client_id'),
            default => collect(),
        })->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        $relatedClients = $relatedClientIds->isEmpty()
            ? collect()
            : Client::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($relatedClientIds)
                ->get(['id', 'name', 'code'])
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'code' => $client->code,
                ])
                ->values();

        return response()->json([
            'segment' => $segment,
            'data' => $mapped,
            'relatedJobs' => $relatedJobModels
                ->map(fn (FlowJob $job): array => $this->mapJobList($job, $canViewFinancials))
                ->values(),
            'relatedClients' => $relatedClients,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'hasMorePages' => $paginator->hasMorePages(),
                'nextPageUrl' => $paginator->nextPageUrl(),
            ],
        ])->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    public function showClient(
        Request $request,
        string $record,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $authorization->allows($user, 'clients', 'view'), 403);

        $client = $this->whereRecordIdentifier(
            $authorization->applyClientVisibility(Client::query(), $user),
            $record,
        )
            ->with('accountManager:id,name,initials')
            ->withCount([
                'jobs as active_jobs_count' => fn (Builder $query) => $authorization->applyJobVisibility($query, $user)->where('status', '!=', 'completed'),
                'jobs as attention_jobs_count' => fn (Builder $query) => $authorization->applyJobVisibility($query, $user)->where('attention_required', true),
            ])
            ->firstOrFail();

        $jobs = $authorization->applyJobVisibility(FlowJob::query(), $user)
            ->where('client_id', $client->id)
            ->with([
                'client:id,name,code', 'currentPhase:id,name,short_name,sequence', 'startedPhase:id,name,short_name,sequence',
                'product:id,name,code', 'productCategory:id,name,code', 'items.product:id,name,code',
                'items.productCategory:id,name,code', 'currency:id,name,code', 'priority:id,name,code',
                'owner:id,name,initials', 'coordinator:id,name,initials', 'creator:id,name,initials',
            ])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'client' => [...$this->mapClient(
                $client,
                $this->canViewFinancials($user),
                $authorization->canViewSensitiveField($user, 'client_contact_details'),
            ), '_detailLoaded' => true],
            'jobs' => $jobs->map(fn (FlowJob $job) => $this->mapJobList($job, $this->canViewFinancials($user))),
        ]);
    }

    public function showJob(
        Request $request,
        string $record,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $authorization->allows($user, 'jobs', 'view'), 403);

        $job = $this->whereRecordIdentifier(FlowJob::query()->visibleTo($user), $record)->firstOrFail();
        $job = $this->loadJob($job);
        $this->primeJobActivityCache(collect([$job]));
        $tasks = $this->loadJobTasks($job, $user);
        $documents = $authorization->applyDocumentVisibility(FlowDocument::query(), $user)
            ->where('flow_job_id', $job->id)
            ->with([
                'job:id,code,client_id,current_phase_id', 'job.client:id,name',
                'phase:id,name,short_name,sequence', 'taskPackItem:id,title', 'task:id,code,title',
                'category:id,name', 'uploader:id,name', 'currentVersion',
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'job' => [...$this->mapJob($job, $this->canViewFinancials($user)), '_detailLoaded' => true],
            'tasks' => $tasks->map(fn (FlowTask $task) => [...$this->mapTask($task), '_detailLoaded' => true]),
            'documents' => $documents->map(fn (FlowDocument $document) => [...$this->mapDocument($document), '_detailLoaded' => true]),
        ]);
    }

    public function showTask(
        Request $request,
        string $record,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $authorization->allows($user, 'tasks', 'view'), 403);

        $baseTask = $this->whereRecordIdentifier(
            $authorization->applyTaskVisibility(FlowTask::query(), $user),
            $record,
        )->firstOrFail();
        $task = $this->loadTask($baseTask, $user);
        $job = $this->loadJob($task->job);
        $this->primeJobActivityCache(collect([$job]));

        return response()->json([
            'task' => [...$this->mapTask($task), '_detailLoaded' => true],
            'job' => [...$this->mapJob($job, $this->canViewFinancials($user)), '_detailLoaded' => true],
        ]);
    }

    public function showDocument(
        Request $request,
        string $record,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $authorization->allows($user, 'documents', 'view'), 403);

        $document = $this->whereRecordIdentifier(
            $authorization->applyDocumentVisibility(FlowDocument::query(), $user),
            $record,
        )
            ->with([
                'job:id,code,client_id,current_phase_id', 'job.client:id,name',
                'phase:id,name,short_name,sequence', 'taskPackItem:id,title', 'task:id,code,title',
                'category:id,name', 'uploader:id,name', 'currentVersion', 'versions.uploader:id,name,initials',
            ])
            ->firstOrFail();

        return response()->json([
            'document' => [...$this->mapDocument($document), '_detailLoaded' => true],
        ]);
    }

    private function whereRecordIdentifier(Builder $query, string $record, string $codeColumn = 'code'): Builder
    {
        return $query->where(function (Builder $identifier) use ($record, $codeColumn): void {
            if (ctype_digit($record)) {
                $identifier->whereKey((int) $record)->orWhere($codeColumn, $record);
                return;
            }

            $identifier->where($codeColumn, $record);
        });
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

    public function bulkJobs(Request $request, DeleteJobAction $deleteJob): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'action' => ['required', Rule::in(['deactivate', 'delete'])],
            'job_ids' => ['required', 'array', 'min:1', 'max:100'],
            'job_ids.*' => ['required', 'integer'],
        ]);

        $jobs = FlowJob::query()
            ->with(['tasks:id,flow_job_id', 'documents:id,flow_job_id'])
            ->where('workspace_id', $user->currentWorkspaceId())
            ->whereIn('id', collect($data['job_ids'])->map(fn ($id) => (int) $id)->unique()->values())
            ->get();

        abort_if($jobs->isEmpty(), 404, 'No matching Jobs were found.');

        if ($data['action'] === 'delete') {
            abort_unless(app(FlowAuthorizationService::class)->isAdministrator($user), 403);

            foreach ($jobs as $job) {
                abort_unless($user->hasFlowAction('jobs', 'delete', $job), 403);
            }

            $deleted = [];
            foreach ($jobs as $job) {
                $deleted[] = ['dbId' => (int) $job->id, 'id' => $job->code];
                $deleteJob->execute($job);
            }

            return response()->json([
                'message' => count($deleted).' Job'.(count($deleted) === 1 ? '' : 's').' deleted successfully.',
                'deleted' => $deleted,
            ]);
        }

        $authorization = app(FlowAuthorizationService::class);
        foreach ($jobs as $job) {
            abort_unless($authorization->canChangeJobWorkflowStatus($user, $job), 403);
        }

        $deactivated = [];

        DB::transaction(function () use ($jobs, $user, &$deactivated): void {
            foreach ($jobs as $job) {
                $oldStatus = $job->status;
                $job->forceFill([
                    'status' => 'inactive',
                    'health' => $job->health === 'completed' ? 'completed' : 'inactive',
                    'attention_required' => false,
                    'next_action' => 'Deactivated by bulk action',
                ])->save();

                FlowActivity::query()->create([
                    'workspace_id' => $job->workspace_id,
                    'user_id' => $user->id,
                    'flow_job_id' => $job->id,
                    'subject_type' => FlowJob::class,
                    'subject_id' => $job->id,
                    'event' => 'job.bulk_deactivated',
                    'description' => 'Job '.$job->code.' deactivated by bulk action',
                    'properties' => [
                        'from_status' => $oldStatus,
                        'to_status' => 'inactive',
                    ],
                ]);

                $deactivated[] = [
                    'dbId' => (int) $job->id,
                    'id' => $job->code,
                    'status' => 'Inactive',
                    'health' => $job->health === 'completed' ? 'Completed' : 'Inactive',
                    'active' => false,
                ];
            }
        });

        return response()->json([
            'message' => count($deactivated).' Job'.(count($deactivated) === 1 ? '' : 's').' deactivated successfully.',
            'deactivated' => $deactivated,
        ]);
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
            'deletedTask' => ['dbId' => (int) $task->id, 'id' => $task->code],
            'job' => $this->mapJob($this->loadJob($job), $this->canViewFinancials($request->user())),
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

        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
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

    public function linkExistingDocument(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadDocumentAction $action,
    ): JsonResponse {
        $targetJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $targetJob), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $targetJob), 403);

        $data = $request->validate([
            'source_document_id' => ['required', 'integer', Rule::exists('flow_documents', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $request->user()->currentWorkspaceId())
                    ->whereNull('deleted_at'))],
            'workflow_phase_id' => ['required', 'integer', Rule::exists('workflow_phases', 'id')
                ->where(fn ($query) => $query->where('workflow_template_id', $targetJob->workflow_template_id))],
            'task_pack_item_id' => ['nullable', 'integer', Rule::exists('task_pack_items', 'id')
                ->where(fn ($query) => $query->whereIn('task_pack_id', function ($subquery) use ($request): void {
                    $subquery->select('id')->from('task_packs')->where('workspace_id', $request->user()->currentWorkspaceId());
                }))],
            'document_category_id' => ['required', 'integer', Rule::exists('master_records', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $request->user()->currentWorkspaceId())
                    ->where('type', MasterDataType::DocumentCategory->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))],
            'task_id' => ['nullable', 'integer', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query
                    ->where('flow_job_id', $targetJob->id)
                    ->whereNull('deleted_at'))],
        ]);

        $source = FlowDocument::query()
            ->with(['job', 'currentVersion'])
            ->where('workspace_id', $request->user()->currentWorkspaceId())
            ->whereNull('deleted_at')
            ->findOrFail((int) $data['source_document_id']);
        abort_unless($source->job && $authorization->allows($request->user(), 'documents', 'view', $source), 403);

        $version = $source->currentVersion;
        if (! $version || ! Storage::disk($version->disk)->exists($version->path)) {
            throw ValidationException::withMessages([
                'source_document_id' => 'The selected stored document file is no longer available.',
            ]);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'flowtrack-doc-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw ValidationException::withMessages([
                'source_document_id' => 'A temporary copy of the selected document could not be created.',
            ]);
        }

        $sourceStream = null;
        $targetStream = null;
        try {
            $sourceStream = Storage::disk($version->disk)->readStream($version->path);
            $targetStream = fopen($temporaryPath, 'w+b');
            if (! is_resource($sourceStream) || ! is_resource($targetStream)) {
                throw ValidationException::withMessages([
                    'source_document_id' => 'The selected stored document could not be read.',
                ]);
            }
            stream_copy_to_stream($sourceStream, $targetStream);
            fclose($sourceStream);
            fclose($targetStream);
            $sourceStream = null;
            $targetStream = null;

            $uploadedFile = new UploadedFile(
                $temporaryPath,
                $version->original_name ?: ($source->name ?: 'document'),
                $version->mime_type ?: null,
                null,
                true,
            );

            $document = $action->execute(
                $targetJob,
                $uploadedFile,
                (int) $data['document_category_id'],
                true,
                $request->user(),
                null,
                'Linked from stored document '.$source->code,
                (int) $data['workflow_phase_id'],
                isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
                false,
                isset($data['task_id']) ? (int) $data['task_id'] : null,
            );
        } finally {
            if (is_resource($sourceStream)) fclose($sourceStream);
            if (is_resource($targetStream)) fclose($targetStream);
            @unlink($temporaryPath);
        }

        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($targetJob);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => 'Stored document linked to the Job.',
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function storeJobAttachment(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadJobAttachmentAction $attachmentAction,
    ): JsonResponse {
        $targetJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $targetJob), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $targetJob), 403);

        $request->validate([
            'file' => ['bail', 'required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,webp,zip,txt,csv'],
        ], [
            'file.required' => 'Choose a document before uploading.',
            'file.uploaded' => 'The server rejected the upload before FlowTrack received it. Confirm the PHP and Nginx upload limits from the deployment guide.',
            'file.max' => 'The document must be 20 MB or smaller.',
            'file.mimes' => 'Use a PDF, Office, image, ZIP, TXT, or CSV document.',
        ]);

        $document = $attachmentAction->execute($targetJob, $request->file('file'), $request->user());
        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($targetJob);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => 'Attachment uploaded successfully.',
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function linkExistingJobAttachment(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadJobAttachmentAction $attachmentAction,
    ): JsonResponse {
        $targetJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $targetJob), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $targetJob), 403);

        $data = $request->validate([
            'source_document_id' => ['required', 'integer', Rule::exists('flow_documents', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $request->user()->currentWorkspaceId())
                    ->whereNull('deleted_at'))],
        ]);

        $source = FlowDocument::query()
            ->with(['job', 'currentVersion'])
            ->where('workspace_id', $request->user()->currentWorkspaceId())
            ->whereNull('deleted_at')
            ->findOrFail((int) $data['source_document_id']);
        abort_unless($source->job && $authorization->allows($request->user(), 'documents', 'view', $source), 403);

        $document = $attachmentAction->copyExisting($targetJob, $source, $request->user());
        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($targetJob);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => 'Stored document attached to the Job.',
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function destroyDocument(
        Request $request,
        FlowDocument $document,
        WorkflowAutomationService $automation,
        TaskPackActivator $taskPackActivator,
        ProgressCalculator $progress,
        ActivityLogger $activities,
        FlowNotificationService $notifications,
        DeleteDocumentAction $action,
    ): JsonResponse {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('documents', 'delete', $document), 403);

        $deletedPhaseId = (int) ($document->workflow_phase_id ?? 0);
        $deletedTaskId = (int) ($document->flow_task_id ?? 0);
        $job = $action->execute($document, $request->user());
        if ($job) {
            $job = $job->fresh(['currentPhase']);

            // Required-document Tasks must never remain Completed after their
            // evidence has been removed. Reconcile the current Task Pack first;
            // TaskPackActivator reopens the matching document Task when the
            // exact required file is no longer present.
            if ($job->currentPhase && (int) $job->current_phase_id === $deletedPhaseId) {
                $taskPackActivator->activate($job, $job->currentPhase, $request->user(), 'document_deletion');
                $job = $job->fresh(['currentPhase']);
            }

            // Generic mandatory Task attachments are not always Task Pack
            // document items. If the exact Task-linked evidence is removed,
            // reopen that Task so it cannot remain completed without the file.
            if ($deletedTaskId) {
                $this->reopenTaskWhenRequiredDocumentIsMissing(
                    $job,
                    $deletedTaskId,
                    $request->user(),
                    $progress,
                    $activities,
                    $notifications,
                );
            }

            $automation->synchronize($job, $request->user(), 'document_deletion', false);
        }

        $payload = ['message' => 'Document and all stored versions deleted successfully.'];
        if ($job) {
            $updatedJob = $this->loadJob($job->fresh());
            $tasks = $this->loadJobTasks($updatedJob, $request->user());
            $payload['job'] = $this->mapJob($updatedJob, $this->canViewFinancials($request->user()));
            $payload['tasks'] = $tasks->map(fn (FlowTask $task) => $this->mapTask($task));
            $payload['deletedTaskId'] = $deletedTaskId ?: null;
        }

        return response()->json($payload);
    }

    private function reopenTaskWhenRequiredDocumentIsMissing(
        FlowJob $job,
        int $taskId,
        User $actor,
        ProgressCalculator $progress,
        ActivityLogger $activities,
        FlowNotificationService $notifications,
    ): void {
        $task = FlowTask::query()
            ->with(['status', 'packItem.documentCategory', 'documentCategory'])
            ->where('flow_job_id', $job->id)
            ->whereNull('deleted_at')
            ->find($taskId);

        if (! $task) {
            return;
        }

        $categoryId = $task->document_category_id ?: $task->packItem?->document_category_id;
        if (! $categoryId) {
            return;
        }

        $hasEvidence = FlowDocument::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $task->workflow_phase_id)
            ->where('document_category_id', $categoryId)
            ->where('flow_task_id', $task->id)
            ->exists();

        if ($hasEvidence) {
            return;
        }

        $statusCode = strtoupper(trim((string) ($task->status?->code ?? '')));
        $statusName = strtoupper(trim((string) ($task->status?->name ?? '')));
        $isCompleted = (bool) $task->completed_at
            || in_array($statusCode, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'], true)
            || in_array($statusName, ['COMPLETED', 'COMPLETE', 'DONE'], true);

        if (! $isCompleted) {
            return;
        }

        $statusQuery = MasterRecord::query()
            ->where('workspace_id', $job->workspace_id)
            ->where('type', MasterDataType::TaskStatus->value)
            ->where('status', 'active');
        $initialStatus = (clone $statusQuery)
            ->where(function ($query): void {
                $query->whereIn('code', ['READY', 'NS', 'NEW'])
                    ->orWhereIn('name', ['Ready', 'Not Started', 'New']);
            })
            ->orderByRaw("case when upper(code) = 'READY' or upper(name) = 'READY' then 1 when upper(code) = 'NS' or upper(name) = 'NOT STARTED' then 2 else 3 end")
            ->first()
            ?: (clone $statusQuery)->orderBy('sort_order')->orderBy('id')->first();

        if (! $initialStatus) {
            return;
        }

        $previousStatus = $task->status?->name ?: 'Completed';
        $previousProgress = (int) $task->progress;
        $task->forceFill([
            'task_status_id' => $initialStatus->id,
            'progress' => $progress->forTaskStatus($initialStatus),
            'completed_at' => null,
            'attention_required' => false,
            'blocker_reason' => null,
        ])->save();

        $activities->log(
            (int) $job->workspace_id,
            $actor,
            $task,
            'task.document_removed',
            "Task {$task->code} reopened because its required document was deleted",
            [
                'changes' => [
                    ['field' => 'Status', 'from' => $previousStatus, 'to' => $initialStatus->name],
                    ['field' => 'Progress', 'from' => $previousProgress.'%', 'to' => $task->progress.'%'],
                ],
                'source' => 'document_deletion',
            ],
        );

        $notifications->taskChanged($task->id, $actor->id, $task->assignee_id, $previousStatus);
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
        $existingAttachmentIds = collect($data['existing_attachment_document_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($attachmentFiles->count() + $existingAttachmentIds->count() > 10) {
            throw ValidationException::withMessages(['attachments' => 'You can attach up to 10 files to a Job.']);
        }
        $startReason = $data['start_reason'] ?? null;
        if ($startPhase->sequence > 1 && blank($startReason)) {
            $startReason = ($attachmentFiles->isNotEmpty() || $existingAttachmentIds->isNotEmpty())
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
                $existingAttachmentIds,
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

                $attachmentUploadStarted = $attachmentFiles->isNotEmpty() || $existingAttachmentIds->isNotEmpty();
                foreach ($attachmentFiles as $attachment) {
                    $document = $attachmentAction->execute($job, $attachment, $request->user());
                    $version = $document->versions->first();
                    if ($version) {
                        $storedFiles[] = ['disk' => $version->disk, 'path' => $version->path];
                    }
                }

                if ($existingAttachmentIds->isNotEmpty()) {
                    $authorization = app(FlowAuthorizationService::class);
                    $sources = FlowDocument::query()
                        ->with(['job', 'currentVersion'])
                        ->where('workspace_id', $request->user()->currentWorkspaceId())
                        ->whereIn('id', $existingAttachmentIds)
                        ->whereNull('deleted_at')
                        ->get()
                        ->keyBy('id');
                    foreach ($existingAttachmentIds as $sourceId) {
                        $source = $sources->get($sourceId);
                        if (! $source || ! $source->job || ! $authorization->allows($request->user(), 'documents', 'view', $source)) {
                            throw ValidationException::withMessages(['existing_attachment_document_ids' => 'One of the selected stored documents is not available to your account.']);
                        }
                        $document = $attachmentAction->copyExisting($job, $source, $request->user());
                        $version = $document->versions->first();
                        if ($version) {
                            $storedFiles[] = ['disk' => $version->disk, 'path' => $version->path];
                        }
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
            'message' => ($attachmentFiles->isEmpty() && $existingAttachmentIds->isEmpty())
                ? "{$job->code} created successfully."
                : "{$job->code} created successfully with ".($attachmentFiles->count() + $existingAttachmentIds->count())." attachment(s).",
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
        $previousAssignedUserIds = collect([$visible->owner_id, $visible->coordinator_id])
            ->merge($previousMemberIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
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
        $currentAssignedUserIds = collect([$updated->owner_id, $updated->coordinator_id])
            ->merge($newMemberIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $newlyAssigned = $currentAssignedUserIds->diff($previousAssignedUserIds)->values();
        if ($newlyAssigned->isNotEmpty()) {
            DB::afterCommit(fn () => $notifications->jobMembersChanged($updated->id, $request->user()->id, $newlyAssigned->all()));
        }

        $updated = $this->loadJob($updated);
        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
        ]);
    }

    public function storeJobComment(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        ActivityLogger $activity,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'jobs', 'view', $visible), 403);

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ]);
        $comment = trim((string) $data['comment']);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Enter a comment.']);
        }

        $activity->log(
            (int) $visible->workspace_id,
            $request->user(),
            $visible,
            'job.commented',
            $comment,
            [
                'source' => 'job details',
                'comment_added' => true,
                'comment' => $comment,
            ],
        );

        // The controller instance can retain an activity cache during this request.
        // Clear this Job so the response includes the newly-created comment.
        unset($this->jobActivityCache[(int) $visible->id]);
        $updated = $this->loadJob($visible);

        return response()->json([
            'message' => 'Comment added.',
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
        ], 201);
    }

    public function syncJobPhaseTasks(
        Request $request,
        FlowJob $job,
        WorkflowPhase $phase,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        TaskPackActivator $taskPackActivator,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless((int) $phase->workflow_template_id === (int) $visible->workflow_template_id && $phase->is_active, 404);
        abort_unless(
            $request->user()->isFlowAdministrator()
                || $authorization->allows($request->user(), 'tasks', 'create', $visible),
            403,
        );

        $taskPackActivator->activate($visible, $phase, $request->user(), 'job_details_phase_task_sync');
        $updated = $this->loadJob($visible->fresh());
        $tasks = $this->loadJobTasks($updated, $request->user());

        return response()->json([
            'message' => "{$phase->name} tasks synchronized from Task Setup.",
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
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

        return response()->json([
            'message' => "Task {$created->code} created successfully.",
            'task' => $this->mapTask($created->loadMissing([
                'job.client', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                'phase.taskPack', 'packItem.documentCategory', 'documentCategory', 'assignee.workspaceMemberships.department', 'setupAssignee:id,name,initials', 'setupAssignee.workspaceMemberships.department',
                'status', 'priority', 'checklistItems', 'comments.user', 'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
            ])),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
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

        if (filled($data['checklist_add'] ?? null) || array_key_exists('checklist_delete_id', $data)) {
            abort_unless($authorization->canManageTaskChecklist($request->user(), $visibleTask), 403);
        }

        try {
            $updated = $action->execute($visibleTask, $data, $request->user(), 'exact_template_task_drawer');
        } catch (DomainException $exception) {
            $message = $exception->getMessage();
            $field = str_contains(strtolower($message), 'status')
                ? 'task_status_id'
                : (str_contains(strtolower($message), 'assignee')
                    ? 'assignee_id'
                    : (str_contains(strtolower($message), 'checklist') ? 'checklist_add' : 'blocker_reason'));

            throw ValidationException::withMessages([$field => $message]);
        }

        $job = $this->loadJob($updated->job);
        $updated->load([
            'job.client', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
            'phase.taskPack', 'assignee.workspaceMemberships.department', 'setupAssignee:id,name,initials', 'setupAssignee.workspaceMemberships.department', 'status', 'priority', 'checklistItems', 'comments.user',
            'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
        ]);

        return response()->json([
            'task' => $this->mapTask($updated),
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
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

    public function updateJobPhaseControls(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        ActivityLogger $activity,
        WorkflowAutomationService $automation,
        FlowNotificationService $notifications,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->canChangeJobWorkflowStatus($request->user(), $visible), 403);

        $workspaceId = $request->user()->currentWorkspaceId();
        $workspaceMember = Rule::exists('workspace_memberships', 'user_id')
            ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'));
        $data = $request->validate([
            'phase_owner_id' => ['sometimes', 'nullable', 'integer', $workspaceMember],
            'target_date' => ['sometimes', 'nullable', 'date'],
            'health_override' => ['sometimes', 'nullable', Rule::in(['on_track', 'needs_attention', 'at_risk', 'delayed', 'blocked'])],
            'automation_mode' => ['sometimes', 'nullable', Rule::in(['manual', 'automatic'])],
        ]);

        if (filled($data['phase_owner_id'] ?? null)) {
            $activePhaseOwner = WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', (int) $data['phase_owner_id'])
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->whereHas('workspace', fn ($query) => $query->where('is_active', true))
                ->exists();

            if (! $activePhaseOwner) {
                throw ValidationException::withMessages([
                    'phase_owner_id' => 'Choose a user with active workspace and role access.',
                ]);
            }
        }

        if (array_key_exists('phase_owner_id', $data)) {
            abort_unless($authorization->allows($request->user(), 'jobs', 'assign', $visible), 403);
        }

        /** @var FlowJobPhaseHistory|null $history */
        $history = $visible->phaseHistory()
            ->where('workflow_phase_id', $visible->current_phase_id)
            ->first();
        if (! $history) {
            throw ValidationException::withMessages([
                'phase' => 'The current workflow phase history could not be loaded.',
            ]);
        }

        $before = [
            'phase_owner_id' => $history->phase_owner_id,
            'target_date' => $history->target_date?->toDateString(),
            'health_override' => $history->health_override,
            'automation_mode' => $history->automation_mode,
        ];
        $changes = [];
        foreach (['phase_owner_id', 'target_date', 'health_override', 'automation_mode'] as $field) {
            if (! array_key_exists($field, $data)) continue;
            $next = $data[$field];
            if ($field === 'target_date' && blank($next)) $next = null;
            if ($before[$field] === $next || (string) $before[$field] === (string) $next) continue;
            $changes[$field] = ['from' => $before[$field], 'to' => $next];
            $history->{$field} = $next;
        }
        $history->changed_by = $request->user()->id;
        $history->save();

        if (array_key_exists('phase_owner_id', $data) && $data['phase_owner_id']) {
            FlowJobMember::updateOrCreate(
                ['flow_job_id' => $visible->id, 'user_id' => (int) $data['phase_owner_id']],
                [
                    'access_level' => 'member',
                    'can_manage_tasks' => true,
                    'can_upload_documents' => true,
                    'can_view_financials' => false,
                ],
            );
        }

        if (array_key_exists('phase_owner_id', $changes) && filled($changes['phase_owner_id']['to'] ?? null)) {
            $newPhaseOwnerId = (int) $changes['phase_owner_id']['to'];
            DB::afterCommit(fn () => $notifications->jobMembersChanged(
                $visible->id,
                $request->user()->id,
                [$newPhaseOwnerId],
            ));
        }

        if ($changes !== []) {
            $activity->log(
                $visible->workspace_id,
                $request->user(),
                $visible,
                'job.phase_controls_updated',
                "{$visible->currentPhase?->name} phase controls updated",
                [
                    'phase_id' => (int) $visible->current_phase_id,
                    'phase_name' => $visible->currentPhase?->name,
                    'changes' => $changes,
                ],
            );
        }

        $updated = $automation->synchronize($visible->fresh(), $request->user(), 'phase_controls', false);
        $updated = $this->loadJob($updated);
        $this->primeJobActivityCache(collect([$updated]));

        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'message' => 'Phase controls updated.',
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
            'members.user:id,name,initials', 'excludedPhases.phase:id,name,short_name,sequence', 'phaseHistory.phase:id,name,short_name,sequence', 'phaseHistory.changedBy:id,name,initials', 'phaseHistory.phaseOwner:id,name,initials',
            'transitionEvents.actor:id,name,initials', 'transitionEvents.phase:id,name,short_name', 'transitionEvents.task:id,code,title',
        ]);
    }

    private function loadJobTasks(FlowJob $job, User $user): Collection
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
                'status:id,name,code', 'priority:id,name,code', 'creator:id,name,initials',
                'dependency:id,code,title,task_status_id,completed_at', 'dependency.status:id,name,code',
                'dependents:id,dependency_id,code,title,task_status_id,completed_at', 'dependents.status:id,name,code',
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

    private function loadTask(FlowTask $task, User $user): FlowTask
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
            'status:id,name,code', 'priority:id,name,code', 'checklistItems', 'comments.user:id,name,initials',
            'dependency:id,code,title,task_status_id,completed_at', 'dependency.status:id,name,code',
            'dependents:id,dependency_id,code,title,task_status_id,completed_at', 'dependents.status:id,name,code',
            'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
        ]);
    }

    private function mapJobList(FlowJob $job, bool $canViewFinancials = false): array
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

    private function mapTaskList(FlowTask $task): array
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
        // A Task title must never invent a mandatory document requirement.
        // Title/description heuristics are only used to expose an optional
        // attachment area; blocking requirements come exclusively from Setup.
        $isConfiguredDocumentTask = (bool) ($documentCategory && in_array($documentSource, ['workflow_phase', 'task_pack_item'], true));
        $isDocumentTask = $isConfiguredDocumentTask || $this->looksLikeDocumentTask($task);

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
            'mentionableUsers' => [],
            'history' => [],
            'checklist' => [],
            'comments' => [],
            '_detailLoaded' => false,
        ];
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

    private function mapTask(FlowTask $task): array
    {
        $task->loadMissing([
            'packItem.documentCategory', 'documentCategory', 'setupAssignee.workspaceMemberships.department',
            'creator:id,name,initials', 'dependency.status:id,name,code', 'dependents.status:id,name,code',
        ]);
        $effectiveAssignee = $task->assignee ?: ((! $task->assignee_overridden_at && $task->setup_assignee_id) ? $task->setupAssignee : null);
        $effectiveAssigneeId = $task->assignee_id ?: ((! $task->assignee_overridden_at && $task->setup_assignee_id) ? $task->setup_assignee_id : null);
        $membership = $effectiveAssignee?->workspaceMemberships?->first() ?: $task->assignee?->workspaceMemberships?->first();
        $actor = request()->user();
        $authorization = app(FlowAuthorizationService::class);
        $documentCategory = $task->documentCategory ?: $task->packItem?->documentCategory;
        $documentSource = $task->document_requirement_source
            ?: ($task->packItem?->document_category_id ? 'task_pack_item' : null);
        $isConfiguredDocumentTask = (bool) ($documentCategory && in_array($documentSource, ['workflow_phase', 'task_pack_item'], true));
        $isGenericAttachmentTask = ! $isConfiguredDocumentTask && $this->looksLikeDocumentTask($task);
        $isAttachmentTask = $isConfiguredDocumentTask || $isGenericAttachmentTask;

        $canViewDocuments = (bool) ($actor && $isAttachmentTask
            && $authorization->allows($actor, 'documents', 'view', $task->job));
        $canUploadDocument = (bool) ($actor && $isAttachmentTask
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

                    if ($document->flow_task_id) {
                        return (int) $document->flow_task_id === (int) $task->id;
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
                'completed' => (bool) $task->dependency->completed_at,
            ] : null,
            'dependents' => $task->dependents->map(fn (FlowTask $dependent): array => [
                'dbId' => (int) $dependent->id,
                'id' => $dependent->code,
                'title' => $dependent->title,
                'status' => $dependent->status?->name ?? ($dependent->completed_at ? 'Completed' : 'Not Started'),
                'statusCode' => $dependent->status?->code,
                'completed' => (bool) $dependent->completed_at,
            ])->values()->all(),
            'isDocumentTask' => (bool) $isAttachmentTask,
            'documentRequirement' => $isConfiguredDocumentTask ? [
                'key' => $documentSource === 'task_pack_item'
                    ? "task:{$task->task_pack_item_id}:{$documentCategory->id}"
                    : "phase:{$task->workflow_phase_id}:{$documentCategory->id}",
                'source' => $documentSource,
                'sourceLabel' => $documentSource === 'task_pack_item'
                    ? 'Task: '.($task->packItem?->title ?? $task->title)
                    : 'Workflow Phase: '.($task->phase?->name ?? 'Current Phase'),
                'phaseId' => (int) $task->workflow_phase_id,
                'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
                'taskId' => (int) $task->id,
                'categoryId' => (int) $documentCategory->id,
                'categoryName' => $documentCategory->name,
                'genericAttachment' => false,
            ] : ($isGenericAttachmentTask ? [
                'key' => "task-attachment:{$task->id}",
                'source' => 'task_attachment',
                'sourceLabel' => 'Task attachment: '.$task->title,
                'phaseId' => (int) $task->workflow_phase_id,
                'taskPackItemId' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
                'taskId' => (int) $task->id,
                'categoryId' => null,
                'categoryName' => 'Attachment',
                'genericAttachment' => true,
            ] : null),
            'documents' => $documentRows->all(),
            'canUploadDocument' => $canUploadDocument,
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

    private function looksLikeDocumentTask(FlowTask $task): bool
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
