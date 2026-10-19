<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Support\BoardLaneResolver;
use App\Support\OrderStageResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BoardTaskPackService
{
    public const JOBS_PER_PAGE = 3;

    private const INITIAL_TASK_STATUSES = ['not started', 'not start', 'ready', 'to do', 'todo'];

    /**
     * Board Task Pack visibility follows the Tasks role-matrix scope.
     *
     * A Job group is visible only when it contains at least one task the current
     * user may view. Task rows are independently re-authorized before hydration,
     * so qualifying through one task never exposes sibling tasks outside scope.
     */
    public function visibleJobQuery(User $user, bool $includeCompleted = false): Builder
    {
        $access = app(AccessControlService::class);
        $query = FlowJob::query()
            ->whereHas('client', fn (Builder $client) => $client->where('is_active', true))
            ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES);

        if (!$includeCompleted) {
            $query->whereNull('flow_jobs.completed_at')
                ->whereRaw("LOWER(TRIM(flow_jobs.status)) != 'completed'");
        }

        if (!$access->can($user, 'tasks', 'view')) return $query->whereRaw('1 = 0');

        $visibleTaskIds = $access->applyTaskScope(Task::query(), $user)
            ->select('tasks.flow_job_id');

        return $query->whereIn('flow_jobs.id', $visibleTaskIds);
    }

    /**
     * Paginate Job groups first, then retrieve only the tasks for those Job IDs.
     * This avoids loading every task into Livewire, keeps a Job together on one
     * page, and makes query cost proportional to the visible page instead of the
     * workspace's total task count.
     */
    public function paginate(
        User $user,
        array $filters,
        int $perPage = self::JOBS_PER_PAGE,
        string $pageName = 'taskPackPage',
    ): array {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $baseTasks = $this->filteredTaskQuery($user, $filters);
        $quick = (string) ($filters['quick'] ?? 'all');
        $hideCompleted = (bool) ($filters['hide_completed'] ?? true);
        $showCompleted = !$hideCompleted && in_array($quick, ['all', 'createdToday', 'completedThisWeek'], true);
        $openOnly = !$showCompleted;

        $grouped = (clone $baseTasks)
            ->reorder()
            ->select('tasks.flow_job_id')
            ->selectRaw(
                "MIN(CASE
                    WHEN tasks.completed_at IS NOT NULL OR LOWER(TRIM(tasks.status)) = 'completed' THEN 6
                    WHEN tasks.needs_attention = 1 THEN 0
                    WHEN tasks.due_date < ? THEN 1
                    WHEN tasks.due_date = ? THEN 2
                    WHEN LOWER(tasks.priority) = 'critical' THEN 3
                    WHEN LOWER(tasks.priority) = 'high' THEN 4
                    ELSE 5
                END) AS action_rank",
                [$today, $today],
            )
            ->selectRaw("MIN(CASE WHEN tasks.completed_at IS NULL AND LOWER(TRIM(tasks.status)) != 'completed' THEN tasks.due_date END) AS min_due")
            ->selectRaw('MAX(tasks.updated_at) AS last_task_update')
            ->groupBy('tasks.flow_job_id');

        $groupsQuery = DB::query()
            ->fromSub($grouped, 'board_task_pack_groups')
            ->join('flow_jobs as board_task_pack_jobs', 'board_task_pack_jobs.id', '=', 'board_task_pack_groups.flow_job_id')
            ->select([
                'board_task_pack_groups.flow_job_id',
                'board_task_pack_groups.action_rank',
                'board_task_pack_groups.min_due',
                'board_task_pack_groups.last_task_update',
                'board_task_pack_jobs.job_number',
            ]);

        match ((string) ($filters['sort'] ?? 'action')) {
            'due' => $groupsQuery
                ->orderByRaw('board_task_pack_groups.min_due is null')
                ->orderBy('board_task_pack_groups.min_due')
                ->orderBy('board_task_pack_jobs.job_number'),
            'job' => $groupsQuery->orderBy('board_task_pack_jobs.job_number'),
            'updated' => $groupsQuery
                ->orderByDesc('board_task_pack_groups.last_task_update')
                ->orderBy('board_task_pack_jobs.job_number'),
            default => $groupsQuery
                ->orderBy('board_task_pack_groups.action_rank')
                ->orderByRaw('board_task_pack_groups.min_due is null')
                ->orderBy('board_task_pack_groups.min_due')
                ->orderBy('board_task_pack_jobs.job_number'),
        };

        $paginator = $groupsQuery->paginate(max(1, min(25, $perPage)), ['*'], $pageName);
        $jobIds = collect($paginator->items())
            ->pluck('flow_job_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($jobIds->isEmpty()) {
            return [
                'groups' => collect(),
                'paginator' => $paginator,
                'visibleTaskCount' => 0,
            ];
        }

        $jobs = FlowJob::query()
            ->whereIn('id', $jobIds)
            ->select([
                'id', 'job_number', 'title', 'client_id', 'workflow_phase_id', 'created_by',
                'progress', 'status', 'updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'phase:id,name,short_name,sequence,color',
            ])
            ->get()
            ->keyBy('id');

        // All Tasks now follows the My Tasks interaction model: summary cards,
        // phase filters, mentions, assignee search and free-text search are all
        // task-level filters. A qualifying Order may therefore contain only the
        // exact matching task rows instead of re-hydrating unrelated siblings.
        $search = trim((string) ($filters['search'] ?? ''));
        $phase = trim((string) ($filters['phase'] ?? ''));

        // My Tasks-style card/phase filters are task-level filters. Only the
        // exact matching Order tasks are hydrated inside a qualifying Order, so
        // a summary card or phase selection never brings unrelated sibling tasks
        // back into the result. Search remains task-level for the same reason.
        $taskLevelFilter = $quick !== 'all'
            || $phase !== ''
            || filled($filters['assignee'] ?? null)
            || $search !== '';
        // Never hydrate sibling tasks outside the Tasks matrix scope. Group-level
        // filters may choose the Order group, but every task row is re-authorized.
        $tasks = $taskLevelFilter
            ? (clone $baseTasks)->whereIn('tasks.flow_job_id', $jobIds)
            : app(AccessControlService::class)->applyTaskScope(
                Task::query()->whereIn('tasks.flow_job_id', $jobIds),
                $user,
            );

        // Group-level filters intentionally load the surrounding Task Pack for
        // each matching Order. Hide completed is different: it is a display
        // constraint and must also be applied to the hydrated sibling rows.
        // Without this second constraint, a Job qualified through one open task
        // but its completed sibling tasks were added back immediately afterward.
        if (!$taskLevelFilter && $openOnly) {
            $tasks
                ->whereNull('tasks.completed_at')
                ->whereRaw("LOWER(TRIM(tasks.status)) != 'completed'");
        }

        $tasks->select([
                'tasks.id', 'tasks.task_number', 'tasks.flow_job_id', 'tasks.workflow_phase_id', 'tasks.task_pack_task_id',
                'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.priority', 'tasks.progress',
                'tasks.due_date', 'tasks.needs_attention', 'tasks.order_task_flag_id', 'tasks.attention_reason',
                'tasks.completed_at', 'tasks.updated_at',
            ])
            ->with([
                'phase:id,name,short_name,sequence,color',
                'setupTemplate:id,color,automation_key',
                'assignee:id,name,department_id,profile_image_path',
                'orderTaskFlag:id,type,name,color,status,sort_order,metadata',
            ]);

        $this->orderTasks($tasks, (string) ($filters['sort'] ?? 'action'), $today);
        $tasksByJob = $tasks->get()->groupBy('flow_job_id');

        $access = app(AccessControlService::class);
        $displayTimezone = app(WorkspaceSettingsService::class)->displayTimezone();
        $openableJobIds = $access->can($user, 'jobs', 'view')
            ? app(JobService::class)->visibleQuery($user)
                ->whereIn('flow_jobs.id', $jobIds)
                ->pluck('flow_jobs.id')
                ->map(fn ($id) => (int) $id)
                ->flip()
            : collect();

        $groups = $jobIds->map(function (int $jobId) use (
            $jobs,
            $tasksByJob,
            $user,
            $access,
            $displayTimezone,
            $today,
            $openableJobIds,
            $taskLevelFilter,
        ) {
            $job = $jobs->get($jobId);
            if (!$job) return null;

            $canOpenJob = $openableJobIds->has($jobId);
            $taskRows = $tasksByJob->get($jobId, collect())
                ->map(fn (Task $task) => $this->presentTask(
                    $task,
                    $user,
                    $access,
                    $displayTimezone,
                    $today,
                    $canOpenJob,
                    (int) ($job->created_by ?: 0) === (int) $user->id,
                ))
                ->values();

            // Search/assignee/mention filters must never leave an Order group
            // behind without an exact matching task row.
            if ($taskLevelFilter && $taskRows->isEmpty()) return null;

            $jobStage = OrderStageResolver::resolve(
                $job->phase?->name,
                $job->phase?->short_name,
                $job->phase?->sequence,
                (string) $job->status,
            );

            return [
                'id' => (int) $job->id,
                'number' => (string) $job->displayOrderNumber(),
                'title' => (string) $job->title,
                'client' => (string) ($job->client?->name ?: 'No client'),
                'stage' => (string) $jobStage['name'],
                'stageColor' => $job->phase?->color ?: $jobStage['color'],
                'progress' => max(0, min(100, (int) $job->progress)),
                'taskCount' => $taskRows->count(),
                'route' => $canOpenJob ? route('jobs.index', ['open' => $job->id]) : null,
                'tasks' => $taskRows,
            ];
        })->filter()->values();

        return [
            'groups' => $groups,
            'paginator' => $paginator,
            'visibleTaskCount' => $groups->sum('taskCount'),
        ];
    }

    /**
     * Use the exact same summary definitions as My Tasks. All Tasks is restricted
     * to administrators, and MyWorkService already broadens administrator scope
     * to all active Order tasks, so both pages now stay numerically consistent.
     */
    public function metrics(User $user): array
    {
        return app(MyWorkService::class)->metrics($user);
    }

    /** @return list<string> */
    public function phaseOptions(): array
    {
        return app(MyWorkService::class)->orderPhaseOptions();
    }

    /** @return list<string> */
    public function statusOptions(): array
    {
        return app(MasterDataService::class)
            ->active('order_task_status')
            ->pluck('name')
            ->map(fn ($status) => trim((string) $status))
            ->filter()
            ->unique(fn ($status) => strtolower($status))
            ->values()
            ->all();
    }

    public function assigneeOptions(User $user)
    {
        $visibleAssigneeIds = app(AccessControlService::class)
            ->applyTaskScope(Task::query()->whereNotNull('tasks.assignee_id'), $user)
            ->select('tasks.assignee_id');

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', $visibleAssigneeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'profile_image_path']);
    }

    private function filteredTaskQuery(User $user, array $filters): Builder
    {
        $quick = (string) ($filters['quick'] ?? 'all');
        $hideCompleted = (bool) ($filters['hide_completed'] ?? true);
        $showCompleted = !$hideCompleted && in_array($quick, ['all', 'createdToday', 'completedThisWeek'], true);
        $openOnly = !$showCompleted;

        $query = app(AccessControlService::class)->applyTaskScope(Task::query(), $user)
            ->whereHas('job', function (Builder $job) use ($openOnly): void {
                $job->whereHas('client', fn (Builder $client) => $client->where('is_active', true))
                    ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES);
                if ($openOnly) {
                    $job->whereNull('flow_jobs.completed_at')
                        ->whereRaw("LOWER(TRIM(flow_jobs.status)) != 'completed'");
                }
            });

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $prefix = $search.'%';
            $looksLikeReference = preg_match('/^(JOB|TSK|TASK|ORD)[-0-9]/i', $search) === 1;
            $matchingAssigneeIds = $this->matchingAssigneeIdsForSearch($search);

            if ($matchingAssigneeIds !== []) {
                // Assignee-name searches are strict task matches. If a user name
                // matches the search text, do not fall through to an Order/client
                // match and hydrate unrelated sibling tasks from that Order.
                // If none of that assignee's tasks satisfy the remaining filters,
                // the result is intentionally empty.
                $query->whereIn('tasks.assignee_id', $matchingAssigneeIds);
            } else {
                $query->where(function (Builder $inner) use ($like, $prefix, $looksLikeReference): void {
                    $inner->whereLike('tasks.task_number', $looksLikeReference ? $prefix : $like)
                        ->orWhereLike('tasks.title', $like)
                        ->orWhereLike('tasks.attention_reason', $like)
                        ->orWhereHas('attentionFlag', fn (Builder $flag) => $flag->whereLike('name', $like))
                        ->orWhereHas('job', fn (Builder $job) => $job
                            ->whereLike('job_number', $looksLikeReference ? $prefix : $like)
                            ->orWhereLike('order_number', $looksLikeReference ? $prefix : $like)
                            ->orWhereLike('title', $like)
                            ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like)));
                });
            }
        }

        $assignee = trim((string) ($filters['assignee'] ?? ''));
        if ($assignee === 'unassigned') {
            $query->whereNull('tasks.assignee_id');
        } elseif ($assignee !== '') {
            $query->where('tasks.assignee_id', ctype_digit($assignee) ? (int) $assignee : -1);
        }

        $query
            ->when($filters['job'] ?? null, fn (Builder $q, $value) => $q->where('tasks.flow_job_id', $value))
            ->when($filters['client'] ?? null, fn (Builder $q, $value) => $q->whereHas('job', fn (Builder $job) => $job->where('client_id', $value)))
            ->when($filters['status'] ?? null, fn (Builder $q, $value) => $q->whereIn('tasks.status', BoardLaneResolver::databaseStatusValues((string) $value)))
            ->when($filters['due'] ?? null, function (Builder $q, $value): void {
                $today = app(WorkspaceSettingsService::class)->localToday();
                match ($value) {
                    'overdue' => $q->where('tasks.due_date', '<', $today->toDateString()),
                    'today' => $q->where('tasks.due_date', $today->toDateString()),
                    'week' => $q->whereBetween('tasks.due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]),
                    'month' => $q->whereBetween('tasks.due_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()]),
                    'none' => $q->whereNull('tasks.due_date'),
                    default => null,
                };
            });

        $phase = trim((string) ($filters['phase'] ?? ''));
        if ($phase !== '') {
            $normalizedPhase = mb_strtolower($phase);
            $sourcePhaseIds = app(MyWorkService::class)->orderPhaseSourceIdsForName($phase);

            $query->whereHas('phase', function (Builder $phaseQuery) use ($normalizedPhase, $sourcePhaseIds): void {
                $phaseQuery->where(function (Builder $phaseMatch) use ($normalizedPhase, $sourcePhaseIds): void {
                    $phaseMatch->whereRaw('LOWER(TRIM(workflow_phases.name)) = ?', [$normalizedPhase]);

                    if ($sourcePhaseIds !== []) {
                        $phaseMatch
                            ->orWhereIn('workflow_phases.source_workflow_phase_id', $sourcePhaseIds)
                            ->orWhereIn('workflow_phases.id', $sourcePhaseIds);
                    }
                });
            });
        }

        $this->applyQuickFilter($query, $user, $quick);

        if ($openOnly) {
            $query
                ->whereNull('tasks.completed_at')
                ->whereRaw("LOWER(TRIM(tasks.status)) != 'completed'");
        }

        return $query;
    }

    /** @return list<int> */
    private function matchingAssigneeIdsForSearch(string $search): array
    {
        $search = trim($search);
        if ($search === '') return [];

        return User::query()
            ->whereLike('name', '%'.$search.'%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function applyQuickFilter(Builder $query, User $user, string $quick): void
    {
        $workspace = app(WorkspaceSettingsService::class);
        $today = $workspace->localToday();
        $todayDate = $today->toDateString();
        $tomorrow = $today->copy()->addDay()->toDateString();
        $weekStartDate = $today->copy()->startOfWeek()->toDateString();
        $weekEndDate = $today->copy()->endOfWeek()->toDateString();
        [$weekStartUtc, $weekEndUtc] = $workspace->localWeekUtcBounds();
        $initialStatuses = self::INITIAL_TASK_STATUSES;
        $initialStatusPlaceholders = implode(',', array_fill(0, count($initialStatuses), '?'));

        match ($quick) {
            'createdToday' => $query->whereBetween('tasks.created_at', [
                $today->copy()->startOfDay()->utc(),
                $today->copy()->endOfDay()->utc(),
            ]),
            'notStarted' => $query
                ->whereRaw('COALESCE(tasks.progress, 0) = 0')
                ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) IN ($initialStatusPlaceholders)", $initialStatuses),
            'inProgress' => $query->where(function (Builder $started) use ($initialStatuses, $initialStatusPlaceholders): void {
                $started->where('tasks.progress', '>', 0)
                    ->orWhere(function (Builder $status) use ($initialStatuses, $initialStatusPlaceholders): void {
                        $status->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) <> ''")
                            ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ($initialStatusPlaceholders)", $initialStatuses);
                    });
            }),
            'dueThisWeek' => $query->whereBetween('tasks.due_date', [$weekStartDate, $weekEndDate]),
            'completedThisWeek' => $query
                ->whereNotNull('tasks.completed_at')
                ->whereBetween('tasks.completed_at', [$weekStartUtc, $weekEndUtc]),
            'attention' => $query->where(function (Builder $attention) use ($todayDate): void {
                $attention->where('tasks.needs_attention', true)
                    ->orWhereNotNull('tasks.order_task_flag_id')
                    ->orWhereNull('tasks.assignee_id')
                    ->orWhereDate('tasks.due_date', '<', $todayDate)
                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%blocked%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%revision%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%overdue%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%delayed%'")
                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%attention%'");
            }),
            // Preserve old bookmarks while the visible All Tasks toolbar now
            // mirrors the My Tasks filters.
            'overdue' => $query->where('tasks.due_date', '<', $todayDate),
            'today' => $query->where('tasks.due_date', $todayDate),
            'upcoming' => $query
                ->whereBetween('tasks.due_date', [$tomorrow, $weekEndDate])
                ->whereRaw("LOWER(TRIM(tasks.status)) NOT LIKE 'waiting%'"),
            'waiting' => $query->whereRaw("LOWER(TRIM(tasks.status)) LIKE 'waiting%'"),
            'mentions' => $query->whereExists($this->commentMentionExistsSubquery()),
            default => null,
        };
    }

    private function commentMentionExistsSubquery(): \Closure
    {
        // Mentions on All Tasks are about the task itself, not about whether the
        // currently signed-in user received a notification. TaskService stores
        // MentionService's parsed IDs on the exact task.comment activity. This
        // keeps the filter user-agnostic and prevents an Order-level mention,
        // description mention, email address, or sibling-task mention from
        // qualifying the wrong task.
        return fn ($activity) => $activity
            ->selectRaw('1')
            ->from('activities as board_task_mention_activity')
            ->whereColumn('board_task_mention_activity.subject_id', 'tasks.id')
            ->where('board_task_mention_activity.subject_type', Task::class)
            ->where('board_task_mention_activity.event', 'task.comment')
            ->whereNotNull('board_task_mention_activity.meta')
            // MySQL normalizes JSON whitespace, so string matching the serialized
            // object is unreliable. Inspect the parsed mention_user_ids array
            // directly so All Tasks and My Work agree in local and cloud builds.
            ->whereJsonLength('board_task_mention_activity.meta->mention_user_ids', '>', 0);
    }

    private function orderTasks(Builder $query, string $sort, string $today): void
    {
        match ($sort) {
            'due' => $query
                ->orderByRaw("CASE WHEN tasks.completed_at IS NOT NULL OR LOWER(TRIM(tasks.status)) = 'completed' THEN 1 ELSE 0 END")
                ->orderByRaw('tasks.due_date is null')
                ->orderBy('tasks.due_date')
                ->orderBy('tasks.id'),
            'job' => $query
                ->orderByRaw("CASE WHEN tasks.completed_at IS NOT NULL OR LOWER(TRIM(tasks.status)) = 'completed' THEN 1 ELSE 0 END")
                ->orderBy('tasks.workflow_phase_id')
                ->orderBy('tasks.id'),
            'updated' => $query
                ->orderByRaw("CASE WHEN tasks.completed_at IS NOT NULL OR LOWER(TRIM(tasks.status)) = 'completed' THEN 1 ELSE 0 END")
                ->orderByDesc('tasks.updated_at')
                ->orderBy('tasks.id'),
            default => $query
                ->orderByRaw(
                    "CASE
                        WHEN tasks.completed_at IS NOT NULL OR LOWER(TRIM(tasks.status)) = 'completed' THEN 6
                        WHEN tasks.needs_attention = 1 THEN 0
                        WHEN tasks.due_date < ? THEN 1
                        WHEN tasks.due_date = ? THEN 2
                        WHEN LOWER(tasks.priority) = 'critical' THEN 3
                        WHEN LOWER(tasks.priority) = 'high' THEN 4
                        ELSE 5
                    END",
                    [$today, $today],
                )
                ->orderByRaw('tasks.due_date is null')
                ->orderBy('tasks.due_date')
                ->orderBy('tasks.id'),
        };
    }

    private function presentTask(
        Task $task,
        User $user,
        AccessControlService $access,
        string $displayTimezone,
        string $today,
        bool $canOpenJob,
        bool $parentCreatedByUser,
    ): array {
        $completed = $task->completed_at !== null || BoardLaneResolver::isCompleted((string) $task->status);
        $dueDate = $task->due_date?->format('Y-m-d');
        $dueLabel = 'No due date';
        $dueTone = 'normal';

        if ($dueDate) {
            if ($completed) {
                $dueLabel = $task->due_date?->format('M j') ?: 'No due date';
            } elseif ($dueDate < $today) {
                $days = abs((int) app(WorkspaceSettingsService::class)->localToday()->diffInDays($task->due_date, false));
                $dueLabel = 'Overdue '.max(1, $days).' '.($days === 1 ? 'day' : 'days');
                $dueTone = 'overdue';
            } elseif ($dueDate === $today) {
                $dueLabel = 'Today';
                $dueTone = 'today';
            } else {
                $dueLabel = $task->due_date?->format('M j') ?: 'No due date';
            }
        }

        $flag = 'No flag';
        if (!$completed) {
            $flag = app(TaskFlagService::class)->labelForTask($task) ?: 'No flag';
        }

        $updatedAt = $task->updated_at?->copy()->setTimezone($displayTimezone);
        $master = app(MasterDataService::class);
        $statusColor = $master->colorFor('order_task_status', (string) $task->status);
        $flagColor = (!$completed && $flag !== 'No flag') ? $master->colorFor('order_task_flag', $flag) : null;
        $taskStage = OrderStageResolver::resolve(
            $task->phase?->name,
            $task->phase?->short_name,
            $task->phase?->sequence,
            (string) $task->status,
            $task->setupTemplate?->automation_key,
        );

        return [
            'id' => (int) $task->id,
            'number' => (string) $task->task_number,
            'title' => (string) $task->title,
            'assignee' => (string) ($task->assignee?->name ?: 'Unassigned'),
            'assigneeImage' => $task->assignee?->profileImageUrl(),
            'isMine' => (int) ($task->assignee_id ?: 0) === (int) $user->id,
            'phase' => (string) $taskStage['short_name'],
            'phaseSequence' => (int) $taskStage['sequence'],
            'phaseColor' => $task->phase?->color ?: $taskStage['color'],
            'taskColor' => \App\Support\MasterColor::normalize((string) ($task->setupTemplate?->color ?? ''))
                ?: \App\Support\MasterColor::normalize((string) ($task->phase?->color ?? ''))
                ?: '#2563EB',
            'due' => $dueLabel,
            'dueTone' => $dueTone,
            'status' => (string) $task->status,
            'statusColor' => $statusColor,
            'flag' => $flag,
            'flagTone' => $this->tone($flag),
            'flagColor' => $flagColor,
            'updated' => $updatedAt?->diffForHumans() ?: '—',
            'version' => (string) $task->getRawOriginal('updated_at'),
            'canEdit' => $this->canEditTaskWithoutQuery($user, $task, $access, $parentCreatedByUser),
            'route' => $canOpenJob ? route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]) : null,
        ];
    }

    /**
     * Mirror task-edit authorization using already eager-loaded fields so the
     * list does not execute one authorization query per task row.
     */
    private function canEditTaskWithoutQuery(User $user, Task $task, AccessControlService $access, bool $parentCreatedByUser = false): bool
    {
        if ($access->isAdministrator($user) || $parentCreatedByUser) return true;
        if (!$access->can($user, 'tasks', 'edit')) return false;

        $scopes = $access->scopes($user, 'tasks');
        $isOwnTask = (int) ($task->assignee_id ?: 0) === (int) $user->id;

        if (in_array('all_records', $scopes, true)) {
            return $access->canEditAll($user, 'tasks')
                || ($isOwnTask && $access->canEditOwn($user, 'tasks'));
        }

        if (in_array('department', $scopes, true)) {
            $sameDepartment = $user->department_id
                && (int) ($task->assignee?->department_id ?: 0) === (int) $user->department_id;

            if ($sameDepartment && $access->canEditAll($user, 'tasks')) return true;
        }

        // Assigned/own task scopes stay assignee-strict even when another role
        // contributes a department scope.
        return $isOwnTask && ($access->canEditOwn($user, 'tasks') || $access->canEditAll($user, 'tasks'));
    }

    private function tone(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') return 'green';
        if (str_contains($value, 'overdue') || str_contains($value, 'blocked') || str_contains($value, 'risk') || str_contains($value, 'delayed')) return 'red';
        if (str_contains($value, 'critical') || str_contains($value, 'today') || str_contains($value, 'wait') || str_contains($value, 'revision') || str_contains($value, 'dependency') || str_contains($value, 'watch') || str_contains($value, 'attention')) return 'amber';
        if (str_contains($value, 'unassigned') || str_contains($value, 'qc')) return 'blue';
        return 'green';
    }
}
