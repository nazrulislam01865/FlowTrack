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


final class ReadApiController extends BaseApiController
{
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
            $this->buildFreshDynamicPayload(
                $request,
                $authorization,
                $notificationFeed,
                $roleMatrixService,
                $roleCatalog,
                $currentUserPresenter,
                $metrics,
            ),
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
     * Keep login/bootstrap intentionally small. Page-specific configuration,
     * master data, setup records and metrics are loaded only when that page is
     * opened through pageContext(). Notifications remain global because the
     * header badge is visible throughout the application.
     *
     * @return array<string, mixed>
     */
    private function buildFreshDynamicPayload(
        Request $request,
        FlowAuthorizationService $authorization,
        NotificationFeedService $notificationFeed,
        RoleMatrixService $roleMatrixService,
        RoleMatrixCatalog $roleCatalog,
        CurrentUserPresenter $currentUserPresenter,
        OperationalMetricsService $metrics,
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

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

        return [
            'currentUser' => $currentUserPresenter->toArray($user),
            'notifications' => $notifications,
            'notificationAccess' => [
                'authorityView' => $authorityNotificationView,
                'statusEndpoint' => route('flowtrack.notifications.status'),
            ],
            'capabilities' => $this->capabilitiesPayload($user, $roleCatalog, $authorization),
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
        abort_unless($user, 401);

        // IMPORTANT: this payload is deliberately shell-only. Do not add Jobs,
        // Tasks, Clients, Documents, workflows, Task Packs, master data, access
        // matrices, activities or aggregate metrics here. Those belong to the
        // page context/list endpoints so login cost stays nearly constant as the
        // workspace grows.
        return [
            'currentUser' => $currentUserPresenter->toArray($user),
            'segments' => [
                'clients' => route('flowtrack.template-api.lists.index', ['segment' => 'clients'], false),
                'jobs' => route('flowtrack.template-api.lists.index', ['segment' => 'jobs'], false),
                'tasks' => route('flowtrack.template-api.lists.index', ['segment' => 'tasks'], false),
                'documents' => route('flowtrack.template-api.lists.index', ['segment' => 'documents'], false),
                'perPage' => max(20, min(100, (int) config('flowtrack.list_per_page', 50))),
            ],
            'contextBaseUrl' => '/flowtrack/template-api/context',
            'clients' => [],
            'jobs' => [],
            'tasks' => [],
            'documents' => [],
            'capabilities' => $this->capabilitiesPayload($user, $roleCatalog, $authorization),
        ];
    }

    /**
     * Page-scoped configuration and aggregate data. This endpoint is the second
     * half of the lazy-loading contract: login returns only the application
     * shell, then the active page asks for exactly the configuration it needs.
     */
    public function pageContext(
        Request $request,
        string $page,
        FlowAuthorizationService $authorization,
        RoleMatrixService $roleMatrixService,
        OperationalMetricsService $metrics,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user, 401);

        $page = strtolower(trim($page));
        $permission = match ($page) {
            'dashboard' => ['dashboard', 'view'],
            'mywork' => ['tasks', 'view'],
            'jobs' => ['jobs', 'view'],
            'clients' => ['clients', 'view'],
            'board' => ['jobs', 'view'],
            'documents' => ['documents', 'view'],
            'reports' => ['reports', 'view'],
            'workflowsetup', 'taskpacksetup' => ['workflow', 'view'],
            'masterdata' => ['masterdata', 'view'],
            'setup' => ['users', 'view'],
            'notifications', 'profile' => null,
            default => abort(404),
        };
        if ($permission) {
            abort_unless($authorization->allows($user, $permission[0], $permission[1]), 403);
        }

        $workspaceId = (int) $user->currentWorkspaceId();
        $canViewUsers = $user->hasFlowAction('users', 'view') || $user->isFlowAdministrator();
        $canViewMasterData = $authorization->allows($user, 'masterdata', 'view');
        $canViewWorkflowSetup = $authorization->allows($user, 'workflow', 'view');
        $canCreateJobs = $authorization->allows($user, 'jobs', 'create');
        $payload = ['page' => $page];

        $needsUsers = in_array($page, ['mywork', 'jobs', 'clients', 'board', 'taskpacksetup', 'setup'], true);
        if ($needsUsers) {
            $payload['users'] = $this->workspaceUsers($user, $authorization, $workspaceId, $canViewUsers);
        }
        if (in_array($page, ['mywork', 'board'], true)) {
            $payload['boardAssigneeUsers'] = $this->boardAssigneeUsers($user, $authorization);
        }

        $needsMaster = in_array($page, ['mywork', 'jobs', 'board', 'documents', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup'], true);
        if ($needsMaster) {
            $master = $this->workspaceMasterRecords($workspaceId, $canViewMasterData);
            $payload['masterData'] = $this->mapMasterData($master);
            $payload['masterDataCatalog'] = collect(MasterDataType::cases())
                ->map(fn (MasterDataType $type) => $type->catalogEntry())->values()->all();
        }

        $needsWorkflows = in_array($page, ['jobs', 'board', 'workflowsetup', 'taskpacksetup'], true);
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
                ->orderByDesc('is_default')->orderBy('name')->get();
            $payload['workflows'] = $workflows->map(fn (WorkflowTemplate $workflow) => $this->mapWorkflow($workflow));
        }

        if (in_array($page, ['workflowsetup', 'taskpacksetup'], true) && $canViewWorkflowSetup) {
            $taskPacks = TaskPack::query()
                ->where('workspace_id', $workspaceId)
                ->with([
                    'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                    'items.defaultAssignee:id,name,initials',
                    'items.defaultDepartment:id,name,code',
                    'items.priority:id,name,code',
                    'items.documentCategory:id,name,code',
                ])->orderBy('name')->get();
            $payload['taskPacks'] = $taskPacks->map(fn (TaskPack $taskPack) => $this->mapTaskPack($taskPack));
        }

        if ($page === 'dashboard' && $authorization->allows($user, 'tasks', 'view')) {
            $payload['activities'] = $authorization->applyTaskVisibility(FlowTask::query(), $user)
                ->with(['job:id,code', 'creator:id,name,initials'])->latest('id')->limit(6)->get()
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
                ])->values();
        }

        if ($page === 'board') {
            $payload['boardJobIds'] = $authorization->applyJobBoardVisibility(FlowJob::query(), $user)
                ->where('status', '!=', 'completed')->pluck('id')->map(fn ($id) => (int) $id)->values();
            $payload['boardAccess'] = [
                'isAdministrator' => $authorization->isAdministrator($user),
                'taskVisibility' => $authorization->isAdministrator($user) ? 'workspace' : 'assigned_job_tasks',
            ];
        }

        if ($page === 'setup' && $canViewUsers) {
            $payload['accessControl'] = [
                ...$roleMatrixService->accessPayload($workspaceId),
                'canManage' => $user->hasFlowAction('users', 'manage') || $user->isFlowAdministrator(),
                'canCreateUsers' => $user->isFlowAdministrator(),
                'canDeleteUsers' => $user->isFlowAdministrator(),
                'isAdmin' => $user->isFlowAdministrator(),
                'isSuperAdmin' => $user->isFlowSuperAdmin(),
            ];
            $payload['notificationRules'] = config('flowtrack.notification_rules', []);
        }

        if (in_array($page, ['dashboard', 'mywork', 'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup'], true)) {
            $payload['summaryMetrics'] = $metrics->forPage($user, $page);
        }

        return response()->json($payload)->header('Cache-Control', 'private, no-cache, must-revalidate');
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
        $perPage = max(20, min(100, (int) $request->integer(
            'per_page',
            (int) config('flowtrack.list_per_page', 50),
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

}
