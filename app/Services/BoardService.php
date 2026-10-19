<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\FlowJobPhaseHistory;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Support\BoardLaneResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BoardService
{
    public const CARD_LIMIT = 60;

    public function __construct(
        private readonly JobService $jobs,
        private readonly TaskService $tasks,
    ) {}

    public function jobs(User $user, array $filters = [], int $limit = self::CARD_LIMIT): Collection
    {
        return $this->jobQuery($user, $filters)
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.client_id', 'flow_jobs.workflow_id',
                'flow_jobs.source_workflow_id', 'flow_jobs.workflow_phase_id', 'flow_jobs.source_workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.coordinator_id',
                'flow_jobs.title', 'flow_jobs.quantity', 'flow_jobs.commercial_value', 'flow_jobs.currency',
                'flow_jobs.status', 'flow_jobs.priority', 'flow_jobs.progress',
                'flow_jobs.delivery_date', 'flow_jobs.next_action', 'flow_jobs.needs_attention', 'flow_jobs.attention_requested',
                'flow_jobs.completed_at', 'flow_jobs.created_at', 'flow_jobs.updated_at',
            ])
            ->addSelect([
                'phase_entered_at' => FlowJobPhaseHistory::query()
                    ->select('entered_at')
                    ->whereColumn('flow_job_id', 'flow_jobs.id')
                    ->whereColumn('workflow_phase_id', 'flow_jobs.workflow_phase_id')
                    ->latest('entered_at')
                    ->limit(1),
            ])
            ->with([
                'client:id,name,logo_path',
                'phase:id,workflow_id,name,short_name,sequence,color',
                'owner:id,name,profile_image_path',
                'coordinator:id,name,profile_image_path',
                'items:id,flow_job_id,quantity',
                'members:id,flow_job_id,user_id',
                'members.user:id,name,profile_image_path',
                'tasks' => fn ($query) => app(AccessControlService::class)
                    ->applyTaskScope($query, $user)
                    ->select([
                        'tasks.id', 'tasks.flow_job_id', 'tasks.workflow_phase_id', 'tasks.assignee_id',
                        'tasks.title', 'tasks.status', 'tasks.priority', 'tasks.due_date',
                        'tasks.completed_at', 'tasks.updated_at',
                    ])
                    ->whereHas('job', fn ($job) => $job->whereColumn('flow_jobs.workflow_phase_id', 'tasks.workflow_phase_id'))
                    ->with(['assignee:id,name,profile_image_path', 'phase:id,name,sequence,color'])
                    ->orderByRaw('completed_at is null desc')
                    ->orderByRaw('due_date is null, due_date asc'),
                'latestActivity' => fn ($query) => $query->select([
                    'activities.id',
                    'activities.subject_type',
                    'activities.subject_id',
                    'activities.user_id',
                    'activities.created_at',
                ]),
                'latestActivity.user:id,name,profile_image_path',
            ])
            ->whereNull('completed_at')
            ->limit(max(1, $limit))
            ->get();
    }

    public function tasks(User $user, array $filters = [], int $limit = self::CARD_LIMIT): Collection
    {
        return $this->taskQuery($user, $filters)
            ->select([
                'tasks.id', 'tasks.task_number', 'tasks.flow_job_id', 'tasks.workflow_phase_id',
                'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.priority', 'tasks.progress',
                'tasks.due_date', 'tasks.needs_attention', 'tasks.attention_reason',
                'tasks.completed_at', 'tasks.created_at', 'tasks.updated_at',
            ])
            ->with([
                'job:id,job_number,title,client_id,coordinator_id,status,completed_at',
                'job.client:id,name,logo_path',
                'phase:id,name,short_name,sequence,color',
                'assignee:id,name,profile_image_path',
            ])
            ->withCount([
                'checklistItems',
                'checklistItems as completed_checklist_items_count' => fn ($query) => $query->where('is_completed', true),
                'comments',
                'documents',
            ])
            ->orderByRaw('due_date is null, due_date asc')
            ->limit(max(1, $limit))
            ->get();
    }

    public function jobCounts(User $user, array $baseFilters = []): array
    {
        $base = $this->jobQuery($user, array_diff_key($baseFilters, ['quick' => true]));
        $today = app(WorkspaceSettingsService::class)->localToday()->format('Y-m-d');
        $weekEnd = app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(7)->format('Y-m-d');

        $row = (clone $base)
            ->reorder()
            ->selectRaw("sum(case when flow_jobs.completed_at is null then 1 else 0 end) as all_count")
            ->selectRaw("sum(case when flow_jobs.completed_at is null and (flow_jobs.owner_id = ? or flow_jobs.coordinator_id = ? or exists (select 1 from flow_job_members where flow_job_members.flow_job_id = flow_jobs.id and flow_job_members.user_id = ?) or exists (select 1 from tasks where tasks.flow_job_id = flow_jobs.id and tasks.assignee_id = ? and tasks.deleted_at is null)) then 1 else 0 end) as mine_count", [$user->id, $user->id, $user->id, $user->id])
            ->selectRaw("sum(case when flow_jobs.completed_at is null and flow_jobs.delivery_date < ? then 1 else 0 end) as overdue_count", [$today])
            ->selectRaw("sum(case when flow_jobs.completed_at is null and flow_jobs.delivery_date between ? and ? then 1 else 0 end) as week_count", [$today, $weekEnd])
            ->selectRaw("sum(case when flow_jobs.completed_at is null and (flow_jobs.status = 'Blocked' or exists (select 1 from tasks where tasks.flow_job_id = flow_jobs.id and tasks.status = 'Blocked' and tasks.completed_at is null and tasks.deleted_at is null)) then 1 else 0 end) as blocked_count")
            ->selectRaw("sum(case when flow_jobs.completed_at is null and (flow_jobs.status in ('Waiting for Client','Waiting for Supplier','Waiting for Internal Approval') or exists (select 1 from tasks where tasks.flow_job_id = flow_jobs.id and tasks.status in ('Waiting for Client','Waiting for Supplier','Waiting for Internal Approval') and tasks.completed_at is null and tasks.deleted_at is null)) then 1 else 0 end) as waiting_count")
            ->selectRaw("sum(case when flow_jobs.completed_at is null and (flow_jobs.owner_id is null or flow_jobs.coordinator_id is null or exists (select 1 from tasks where tasks.flow_job_id = flow_jobs.id and tasks.assignee_id is null and tasks.completed_at is null and tasks.deleted_at is null)) then 1 else 0 end) as unassigned_count")
            ->first();

        return [
            'all' => (int) ($row?->all_count ?? 0),
            'mine' => (int) ($row?->mine_count ?? 0),
            'overdue' => (int) ($row?->overdue_count ?? 0),
            'week' => (int) ($row?->week_count ?? 0),
            'blocked' => (int) ($row?->blocked_count ?? 0),
            'waiting' => (int) ($row?->waiting_count ?? 0),
            'unassigned' => (int) ($row?->unassigned_count ?? 0),
        ];
    }

    public function taskCounts(User $user, array $baseFilters = []): array
    {
        $base = $this->taskQuery($user, array_diff_key($baseFilters, ['quick' => true, 'open_only' => true]));

        $row = (clone $base)
            ->reorder()
            ->selectRaw("sum(case when completed_at is null then 1 else 0 end) as open_count")
            ->selectRaw("sum(case when completed_at is null and assignee_id = ? then 1 else 0 end) as mine_count", [$user->id])
            ->selectRaw("sum(case when completed_at is null and due_date < ? then 1 else 0 end) as overdue_count", [app(WorkspaceSettingsService::class)->localToday()->format('Y-m-d')])
            ->selectRaw("sum(case when completed_at is null and due_date between ? and ? then 1 else 0 end) as week_count", [app(WorkspaceSettingsService::class)->localToday()->format('Y-m-d'), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(7)->format('Y-m-d')])
            ->selectRaw("sum(case when completed_at is null and status = 'Blocked' then 1 else 0 end) as blocked_count")
            ->selectRaw("sum(case when completed_at is null and status in ('Waiting for Client','Waiting for Supplier','Waiting for Internal Approval') then 1 else 0 end) as waiting_count")
            ->selectRaw("sum(case when completed_at is null and assignee_id is null then 1 else 0 end) as unassigned_count")
            ->selectRaw("sum(case when completed_at is not null or status = 'Completed' then 1 else 0 end) as completed_count")
            ->first();

        return [
            'open' => (int) ($row?->open_count ?? 0),
            'mine' => (int) ($row?->mine_count ?? 0),
            'overdue' => (int) ($row?->overdue_count ?? 0),
            'week' => (int) ($row?->week_count ?? 0),
            'blocked' => (int) ($row?->blocked_count ?? 0),
            'waiting' => (int) ($row?->waiting_count ?? 0),
            'unassigned' => (int) ($row?->unassigned_count ?? 0),
            'completed' => (int) ($row?->completed_count ?? 0),
        ];
    }

    public function phases(?int $workflowId = null): Collection
    {
        if (!$workflowId) {
            $workflowId = WorkflowTemplate::query()
                ->where('workspace_id', app(SetupContext::class)->workspaceId())
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');
        }
        if (!$workflowId) return collect();

        $key = $this->phaseCacheKey($workflowId);
        $expiresAt = now()->addMinutes(5);
        $resolver = function () use ($workflowId) {
            $sourceRows = WorkflowPhase::query()
                ->where(function ($query) use ($workflowId) {
                    $query->where('workflow_template_id', $workflowId)
                        ->orWhere('workflow_id', $workflowId);
                })
                ->where('is_active', true)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get(['id', 'workflow_id', 'source_workflow_phase_id', 'name', 'short_name', 'sequence'])
                ->map(fn (WorkflowPhase $phase) => [
                    'id' => (int) $phase->id,
                    'workflow_id' => (int) $workflowId,
                    'name' => (string) $phase->name,
                    'short_name' => (string) ($phase->short_name ?: $phase->name),
                    'sequence' => (int) $phase->sequence,
                ]);

            $snapshotWorkflowIds = Workflow::query()
                ->where('is_snapshot', true)
                ->where('source_workflow_id', $workflowId)
                ->pluck('id');

            $snapshotRows = $snapshotWorkflowIds->isEmpty()
                ? collect()
                : WorkflowPhase::query()
                    ->whereIn('workflow_id', $snapshotWorkflowIds)
                    ->whereNotNull('source_workflow_phase_id')
                    ->where('is_active', true)
                    ->orderBy('sequence')
                    ->orderBy('id')
                    ->get(['id', 'workflow_id', 'source_workflow_phase_id', 'name', 'short_name', 'sequence'])
                    ->map(fn (WorkflowPhase $phase) => [
                        'id' => (int) $phase->source_workflow_phase_id,
                        'workflow_id' => (int) $workflowId,
                        'name' => (string) $phase->name,
                        'short_name' => (string) ($phase->short_name ?: $phase->name),
                        'sequence' => (int) $phase->sequence,
                    ]);

            return $sourceRows
                ->concat($snapshotRows)
                ->unique('id')
                ->sortBy(['sequence', 'id'])
                ->values()
                ->all();
        };
        $rows = $this->rememberScalarRows(
            $key,
            $expiresAt,
            $resolver,
            ['id', 'workflow_id', 'name', 'short_name', 'sequence'],
        );

        return $this->rowObjects($rows, ['id', 'workflow_id', 'name', 'short_name', 'sequence']);
    }

    public function workflowOptions(): Collection
    {
        $workspaceId = app(SetupContext::class)->workspaceId();
        $workflowKey = $this->workflowCacheKey();
        $workflowExpiresAt = now()->addMinutes(5);
        $workflowResolver = function () use ($workspaceId) {
            // Workflow Setup is the only source of truth for this filter. Do not
            // merge deleted legacy Workflows or private Job snapshots back into
            // the Board dropdown.
            return WorkflowTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (WorkflowTemplate $workflow) => [
                    'id' => (int) $workflow->id,
                    'name' => (string) $workflow->name,
                ])
                ->values()
                ->all();
        };

        $rows = $this->rememberScalarRows(
            $workflowKey,
            $workflowExpiresAt,
            $workflowResolver,
            ['id', 'name'],
        );

        return $this->rowObjects($rows, ['id', 'name']);
    }

    public function lookups(User $user, bool $includeWorkflows = true): array
    {
        // Cache only scalar arrays. Laravel 13 defaults to rejecting class
        // unserialization from cache, so storing Eloquent collections here can
        // turn a later lookup into strings/incomplete values and break Blade.
        $lookupKey = $this->lookupCacheKey($user->id);
        $lookupExpiresAt = now()->addMinutes(3);
        $lookupResolver = fn () => $this->buildLookupPayload($user);
        $cached = Cache::remember($lookupKey, $lookupExpiresAt, $lookupResolver);

        if (!$this->lookupPayloadIsValid($cached)) {
            Cache::forget($lookupKey);
            $cached = $lookupResolver();
            Cache::put($lookupKey, $cached, $lookupExpiresAt);
        }

        $workflows = $includeWorkflows ? $this->workflowOptions() : collect();

        return [
            'clients' => $this->rowObjects($cached['clients'] ?? [], ['id', 'name']),
            'users' => $this->rowObjects($cached['users'] ?? [], ['id', 'name']),
            'workflows' => $workflows,
        ];
    }


    private function buildLookupPayload(User $user): array
    {
        $access = app(AccessControlService::class);
        $clients = $access->applyClientScope(Client::where('is_active', true), $user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
            ])
            ->all();

        $users = $access->scope($user, 'tasks') === 'all_records'
            ? User::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                ])
                ->all()
            : [[
                'id' => (int) $user->id,
                'name' => (string) $user->name,
            ]];

        return ['clients' => $clients, 'users' => $users];
    }

    private function lookupPayloadIsValid(mixed $payload): bool
    {
        return is_array($payload)
            && $this->scalarRowsAreValid($payload['clients'] ?? null, ['id', 'name'])
            && $this->scalarRowsAreValid($payload['users'] ?? null, ['id', 'name']);
    }

    private function rememberScalarRows(
        string $key,
        mixed $expiresAt,
        callable $resolver,
        array $requiredFields,
    ): array {
        $rows = Cache::remember($key, $expiresAt, $resolver);

        if (!$this->scalarRowsAreValid($rows, $requiredFields)) {
            Cache::forget($key);
            $rows = $resolver();
            Cache::put($key, $rows, $expiresAt);
        }

        return is_array($rows) ? $rows : [];
    }

    private function scalarRowsAreValid(mixed $rows, array $requiredFields): bool
    {
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }

            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $row)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function rowObjects(mixed $rows, array $fields): Collection
    {
        if ($rows instanceof Collection) {
            $rows = $rows->all();
        }

        if (!is_iterable($rows)) {
            return collect();
        }

        return collect($rows)
            ->map(function (mixed $row) use ($fields): ?object {
                if ($row instanceof \Illuminate\Database\Eloquent\Model) {
                    $row = $row->getAttributes();
                } elseif (is_object($row)) {
                    $row = get_object_vars($row);
                }

                if (!is_array($row)) {
                    return null;
                }

                $attributes = [];
                foreach ($fields as $field) {
                    $attributes[$field] = $row[$field] ?? null;
                }

                if (!isset($attributes['id']) || !is_numeric($attributes['id'])) {
                    return null;
                }

                $attributes['id'] = (int) $attributes['id'];
                if (array_key_exists('workflow_id', $attributes) && is_numeric($attributes['workflow_id'])) {
                    $attributes['workflow_id'] = (int) $attributes['workflow_id'];
                }
                if (array_key_exists('sequence', $attributes)) {
                    $attributes['sequence'] = (int) ($attributes['sequence'] ?? 0);
                }
                foreach (['name', 'short_name'] as $field) {
                    if (array_key_exists($field, $attributes)) {
                        $attributes[$field] = (string) ($attributes[$field] ?? '');
                    }
                }

                return (object) $attributes;
            })
            ->filter()
            ->values();
    }

    private function lookupCacheKey(int $userId): string
    {
        return 'flowtrack:board:lookups:v3:clients-'.app(ClientService::class)->lifecycleVersion().':data-'.app(WorkspaceRefreshService::class)->version().':user:'.$userId;
    }

    public static function workflowOptionsCacheKey(int $workspaceId): string
    {
        return 'flowtrack:board:workflows:v3:workspace:'.$workspaceId;
    }

    public static function workflowPhaseCacheKey(int $workflowId): string
    {
        return 'flowtrack:board:phases:v3:'.$workflowId;
    }

    private function workflowCacheKey(): string
    {
        return self::workflowOptionsCacheKey(app(SetupContext::class)->workspaceId());
    }

    private function phaseCacheKey(int $workflowId): string
    {
        return self::workflowPhaseCacheKey($workflowId);
    }

    private function jobQuery(User $user, array $filters): Builder
    {
        $query = $this->jobs->visibleQuery($user)
            ->whereHas('client', fn ($client) => $client->where('is_active', true));

        $query
            ->when(empty($filters['status']), fn ($q) => $q->whereNotIn('status', JobService::INACTIVE_STATUSES))
            ->when($filters['workflow'] ?? null, fn ($q, $value) => $q->where(function ($workflowQuery) use ($value) {
                $workflowQuery->where('source_workflow_id', $value)
                    ->orWhere(function ($legacy) use ($value) {
                        $legacy->whereNull('source_workflow_id')->where('workflow_id', $value);
                    });
            }))
            ->when($filters['job'] ?? null, fn ($q, $value) => $q->whereKey($value))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->whereLike('job_number', "%{$search}%")
                        ->orWhereLike('title', "%{$search}%")
                        ->orWhereLike('product', "%{$search}%")
                        ->orWhereHas('client', fn ($client) => $client->whereLike('name', "%{$search}%"))
                        ->orWhereHas('tasks', fn ($task) => $task->whereLike('title', "%{$search}%")->orWhereLike('task_number', "%{$search}%"));
                });
            })
            ->when($filters['client'] ?? null, fn ($q, $value) => $q->where('client_id', $value))
            ->when($filters['assignee'] ?? null, fn ($q, $value) => $q->whereHas('tasks', fn ($task) => $task->where('assignee_id', $value)))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['due'] ?? null, function ($q, $value) {
                match ($value) {
                    'overdue' => $q->where('delivery_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString()),
                    'today' => $q->where('delivery_date', app(WorkspaceSettingsService::class)->localToday()->toDateString()),
                    'week' => $q->whereBetween('delivery_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(7)]),
                    'month' => $q->whereBetween('delivery_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(30)]),
                    'none' => $q->whereNull('delivery_date'),
                    default => null,
                };
            })
            ->when($filters['owner'] ?? null, fn ($q, $value) => $q->where(fn ($inner) => $inner->where('owner_id', $value)->orWhere('coordinator_id', $value)))
;

        match ($filters['quick'] ?? '') {
            'mine' => $query->where(fn ($q) => $q
                ->where('owner_id', $user->id)
                ->orWhere('coordinator_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))
                ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $user->id))
            ),
            'overdue' => $query->where('delivery_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString()),
            'week' => $query->whereBetween('delivery_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(7)]),
            'blocked' => $query->where(fn ($q) => $q->where('status', 'Blocked')->orWhereHas('tasks', fn ($t) => $t->where('status', 'Blocked')->whereNull('completed_at'))),
            'waiting' => $query->where(fn ($q) => $q->whereIn('status', ['Waiting for Client', 'Waiting for Supplier', 'Waiting for Internal Approval'])->orWhereHas('tasks', fn ($t) => $t->whereIn('status', ['Waiting for Client', 'Waiting for Supplier', 'Waiting for Internal Approval'])->whereNull('completed_at'))),
            'unassigned' => $query->where(fn ($q) => $q->whereNull('owner_id')->orWhereNull('coordinator_id')->orWhereHas('tasks', fn ($t) => $t->whereNull('assignee_id')->whereNull('completed_at'))),
            default => null,
        };

        $sort = $filters['sort'] ?? 'delivery';
        if ($sort === 'updated') {
            $query->latest('updated_at');
        } elseif ($sort === 'priority') {
            $query->orderByRaw("case priority when 'Critical' then 1 when 'High' then 2 when 'Medium' then 3 else 4 end")->orderBy('delivery_date');
        } else {
            $query->orderByRaw('delivery_date is null, delivery_date asc')->latest('id');
        }

        return $query;
    }

    private function taskQuery(User $user, array $filters): Builder
    {
        $query = $this->tasks->visibleQuery($user)
            ->whereHas('job', fn ($job) => $job
                ->whereHas('client', fn ($client) => $client->where('is_active', true))
                ->whereNull('completed_at')
                ->whereNotIn('status', JobService::INACTIVE_STATUSES));

        if (($filters['open_only'] ?? false) === true) $query->whereNull('completed_at');

        $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->whereLike('title', "%{$search}%")
                        ->orWhereLike('task_number', "%{$search}%")
                        ->orWhereHas('job', fn ($job) => $job
                            ->whereLike('job_number', "%{$search}%")
                            ->orWhereLike('title', "%{$search}%")
                            ->orWhereHas('client', fn ($client) => $client->whereLike('name', "%{$search}%"))
                        );
                });
            })
            ->when($filters['job'] ?? null, fn ($q, $value) => $q->where('flow_job_id', $value))
            ->when($filters['client'] ?? null, fn ($q, $value) => $q->whereHas('job', fn ($job) => $job->where('client_id', $value)))
            ->when($filters['assignee'] ?? null, fn ($q, $value) => $q->where('assignee_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->whereIn('status', BoardLaneResolver::databaseStatusValues((string) $value)))
            ->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->when($filters['due'] ?? null, function ($q, $value) {
                match ($value) {
                    'overdue' => $q->where('due_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString()),
                    'today' => $q->where('due_date', app(WorkspaceSettingsService::class)->localToday()->toDateString()),
                    'week' => $q->whereBetween('due_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(7)]),
                    'month' => $q->whereBetween('due_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(30)]),
                    'none' => $q->whereNull('due_date'),
                    default => null,
                };
            });

        match ($filters['quick'] ?? '') {
            'open' => $query->whereNull('completed_at'),
            'mine' => $query->where('assignee_id', $user->id),
            'overdue' => $query->whereNull('completed_at')->where('due_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString()),
            'week' => $query->whereNull('completed_at')->whereBetween('due_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->copy()->addDays(7)]),
            'blocked' => $query->whereNull('completed_at')->where('status', 'Blocked'),
            'waiting' => $query->whereNull('completed_at')->whereIn('status', ['Waiting for Client', 'Waiting for Supplier', 'Waiting for Internal Approval']),
            'unassigned' => $query->whereNull('completed_at')->whereNull('assignee_id'),
            'completed' => $query->where(fn ($q) => $q->whereNotNull('completed_at')->orWhere('status', 'Completed')),
            default => null,
        };

        return $query;
    }
}
