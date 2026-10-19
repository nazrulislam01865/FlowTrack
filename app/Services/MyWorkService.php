<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Support\OrderStageResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MyWorkService
{
    public const JOBS_PER_PAGE = 10;

    private const INITIAL_TASK_STATUSES = ['not started', 'not start', 'ready', 'to do', 'todo'];
    private const STAGE_CARD_CACHE_TTL_SECONDS = 15;
    private const STAGE_PHASE_MAP_CACHE_TTL_MINUTES = 10;

    /**
     * Paginate Jobs, never individual tasks. The first query selects only a
     * bounded page of Job IDs that contain personal work, then the page loads
     * only the matching task rows for those Jobs.
     */
    public function paginate(User $user, array $filters, int $perPage = self::JOBS_PER_PAGE, string $pageName = 'workPage'): array
    {
        $access = app(AccessControlService::class);

        // My Tasks is strictly an active-work queue. Filters may narrow the
        // active row set, but they never bring completed, locked, previous-phase
        // or future-phase tasks back into an Order group.
        $baseTasks = $this->personalTaskQuery($user, $filters);
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

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
            ->groupBy('tasks.flow_job_id');

        $groupsQuery = DB::query()
            ->fromSub($grouped, 'my_work_groups')
            ->join('flow_jobs as my_work_jobs', 'my_work_jobs.id', '=', 'my_work_groups.flow_job_id')
            ->select([
                'my_work_groups.flow_job_id',
                'my_work_groups.action_rank',
                'my_work_groups.min_due',
                'my_work_jobs.job_number',
            ]);

        match ((string) ($filters['sort'] ?? 'action')) {
            'due' => $groupsQuery
                ->orderByRaw('my_work_groups.min_due is null')
                ->orderBy('my_work_groups.min_due')
                ->orderBy('my_work_jobs.job_number'),
            'job' => $groupsQuery->orderBy('my_work_jobs.job_number'),
            default => $groupsQuery
                ->orderBy('my_work_groups.action_rank')
                ->orderByRaw('my_work_groups.min_due is null')
                ->orderBy('my_work_groups.min_due')
                ->orderBy('my_work_jobs.job_number'),
        };

        $paginator = $groupsQuery->paginate(max(1, min(self::JOBS_PER_PAGE, $perPage)), ['*'], $pageName);
        $jobIds = collect($paginator->items())->pluck('flow_job_id')->map(fn ($id) => (int) $id)->values();

        if ($jobIds->isEmpty()) {
            return [
                'groups' => collect(),
                'paginator' => $paginator,
                'visibleTaskCount' => 0,
            ];
        }

        // Keep the page hydrate query count low. My Work only needs the
        // client/phase labels, so fetch them with LEFT JOINs instead of three
        // separate eager-load queries.
        $jobs = FlowJob::query()
            ->whereIn('flow_jobs.id', $jobIds)
            ->leftJoin('clients as my_work_clients', 'my_work_clients.id', '=', 'flow_jobs.client_id')
            ->leftJoin('workflow_phases as my_work_job_phases', 'my_work_job_phases.id', '=', 'flow_jobs.workflow_phase_id')
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.title', 'flow_jobs.client_id', 'flow_jobs.workflow_phase_id', 'flow_jobs.created_by',
                'flow_jobs.progress', 'flow_jobs.status', 'flow_jobs.updated_at',
                'my_work_clients.name as my_work_client_name',
                'my_work_job_phases.name as my_work_phase_name',
                'my_work_job_phases.short_name as my_work_phase_short_name',
                'my_work_job_phases.color as my_work_phase_color',
                'my_work_job_phases.sequence as my_work_phase_sequence',
            ])
            ->get()
            ->keyBy('id');

        $tasks = (clone $baseTasks)
            ->whereIn('tasks.flow_job_id', $jobIds)
            ->leftJoin('workflow_phases as my_work_task_phases', 'my_work_task_phases.id', '=', 'tasks.workflow_phase_id')
            ->leftJoin('task_pack_items as my_work_task_templates', 'my_work_task_templates.id', '=', 'tasks.task_pack_task_id')
            ->leftJoin('users as my_work_assignees', 'my_work_assignees.id', '=', 'tasks.assignee_id')
            ->leftJoin('master_records as my_work_task_flags', 'my_work_task_flags.id', '=', 'tasks.order_task_flag_id')
            ->select([
                'tasks.id', 'tasks.task_number', 'tasks.flow_job_id', 'tasks.workflow_phase_id',
                'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.priority',
                'tasks.due_date', 'tasks.needs_attention', 'tasks.order_task_flag_id', 'tasks.attention_reason',
                'tasks.completed_at', 'tasks.updated_at',
                'my_work_task_phases.name as my_work_phase_name',
                'my_work_task_phases.short_name as my_work_phase_short_name',
                'my_work_task_phases.color as my_work_phase_color',
                'my_work_task_phases.sequence as my_work_phase_sequence',
                'my_work_task_templates.color as my_work_task_color',
                'my_work_task_templates.automation_key as my_work_automation_key',
                'my_work_assignees.name as my_work_assignee_name',
                'my_work_assignees.profile_image_path as my_work_assignee_profile_image_path',
                'my_work_task_flags.name as task_flag_name',
            ]);

        $this->orderTasks($tasks, (string) ($filters['sort'] ?? 'action'), $today);
        $tasksByJob = $tasks->get()->groupBy('flow_job_id');
        $displayTimezone = app(WorkspaceSettingsService::class)->displayTimezone();
        $canOpenJobs = $access->can($user, 'jobs', 'view');

        $groups = $jobIds->map(function (int $jobId) use ($jobs, $tasksByJob, $user, $access, $displayTimezone, $today, $canOpenJobs) {
            $job = $jobs->get($jobId);
            if (!$job) return null;

            $parentCreatedByUser = (int) ($job->created_by ?: 0) === (int) $user->id;
            $taskRows = $tasksByJob->get($jobId, collect())
                ->map(fn (Task $task) => $this->presentTask($task, $user, $access, $displayTimezone, $today, $parentCreatedByUser))
                ->values();

            $jobStage = OrderStageResolver::resolve(
                $job->getAttribute('my_work_phase_name'),
                $job->getAttribute('my_work_phase_short_name'),
                $job->getAttribute('my_work_phase_sequence') !== null ? (int) $job->getAttribute('my_work_phase_sequence') : null,
                (string) $job->status,
            );

            return [
                'id' => (int) $job->id,
                'number' => (string) $job->displayOrderNumber(),
                'title' => (string) $job->title,
                'client' => (string) ($job->getAttribute('my_work_client_name') ?: 'No client'),
                'stage' => (string) $jobStage['name'],
                'stageColor' => $job->getAttribute('my_work_phase_color') ?: $jobStage['color'],
                'progress' => max(0, min(100, (int) $job->progress)),
                'taskCount' => $taskRows->count(),
                'route' => $canOpenJobs ? route('jobs.index', ['open' => $job->id]) : null,
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
     * Keep My Task counters aligned with the reusable Inquiry/Order summary-card
     * language while counting task records inside the user's personal task scope.
     * One aggregate query keeps this page fast even with a large task history.
     */
    public function metrics(User $user, bool $fresh = false): array
    {
        $workspace = app(WorkspaceSettingsService::class);
        $today = $workspace->localToday();
        $todayDate = $today->toDateString();
        $tomorrow = $today->addDay()->toDateString();
        $weekStartDate = $today->startOfWeek()->toDateString();
        $weekEndDate = $today->endOfWeek()->toDateString();
        $todayStartUtc = $today->utc();
        $todayEndUtc = $today->endOfDay()->utc();

        $taskMentions = DB::table('activities')
            ->selectRaw('subject_id as flow_task_id')
            ->where('subject_type', Task::class)
            ->where('event', 'task.comment')
            ->whereNotNull('meta')
            ->whereJsonLength('meta->mention_user_ids', '>', 0)
            ->groupBy('subject_id');

        // Drive every My Tasks counter from exactly the same active-only,
        // assignee-only scope as the table. Admin and Super Admin intentionally
        // follow the same personal queue rule as every other user.
        $activeVisibleTaskIds = $this->activeVisibleTaskQuery($user)
            ->reorder()
            ->select('tasks.id');

        $query = DB::table('tasks')
            ->join('flow_jobs as my_work_metric_jobs', 'my_work_metric_jobs.id', '=', 'tasks.flow_job_id')
            ->leftJoinSub($taskMentions, 'my_work_metric_task_mentions', fn ($join) => $join->on('my_work_metric_task_mentions.flow_task_id', '=', 'tasks.id'))
            ->whereIn('tasks.id', $activeVisibleTaskIds);

        // All rows in this aggregate are active by construction. Retain the
        // legacy metric keys so dashboard/bookmarked filters continue to work,
        // but completed-task history is deliberately outside My Tasks.
        $notStarted = "COALESCE(tasks.progress, 0) = 0";
        $inProgress = "COALESCE(tasks.progress, 0) > 0
            OR LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ('not start','not started','ready','to do','todo')";
        $needsAttention = "(tasks.needs_attention = 1
            OR tasks.order_task_flag_id IS NOT NULL
            OR tasks.assignee_id IS NULL
            OR tasks.due_date < ?
            OR LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%blocked%'
            OR LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%revision%'
            OR LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%overdue%'
            OR LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%delayed%'
            OR LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%attention%')";

        $row = $query
            ->selectRaw('COUNT(tasks.id) AS my_tasks_count')
            ->selectRaw('SUM(CASE WHEN tasks.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS created_today_count', [$todayStartUtc, $todayEndUtc])
            ->selectRaw("SUM(CASE WHEN $notStarted THEN 1 ELSE 0 END) AS not_started_count")
            ->selectRaw("SUM(CASE WHEN ($inProgress) THEN 1 ELSE 0 END) AS in_progress_count")
            ->selectRaw('SUM(CASE WHEN tasks.due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS due_this_week_count', [$weekStartDate, $weekEndDate])
            ->selectRaw('0 AS completed_this_week_count')
            ->selectRaw("SUM(CASE WHEN $needsAttention THEN 1 ELSE 0 END) AS attention_count", [$todayDate])
            ->selectRaw('SUM(CASE WHEN tasks.due_date < ? THEN 1 ELSE 0 END) AS overdue_count', [$todayDate])
            ->selectRaw('SUM(CASE WHEN tasks.due_date = ? THEN 1 ELSE 0 END) AS today_count', [$todayDate])
            ->selectRaw("SUM(CASE WHEN tasks.due_date BETWEEN ? AND ? AND LOWER(TRIM(tasks.status)) NOT LIKE 'waiting%' THEN 1 ELSE 0 END) AS upcoming_count", [$tomorrow, $weekEndDate])
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(tasks.status)) LIKE 'waiting%' THEN 1 ELSE 0 END) AS waiting_count")
            ->selectRaw('SUM(CASE WHEN my_work_metric_task_mentions.flow_task_id IS NOT NULL THEN 1 ELSE 0 END) AS mentions_count')
            ->first();

        return [
            'my_tasks' => (int) ($row?->my_tasks_count ?? 0),
            'createdToday' => (int) ($row?->created_today_count ?? 0),
            'notStarted' => (int) ($row?->not_started_count ?? 0),
            'inProgress' => (int) ($row?->in_progress_count ?? 0),
            'dueThisWeek' => (int) ($row?->due_this_week_count ?? 0),
            'completedThisWeek' => 0,
            'attention' => (int) ($row?->attention_count ?? 0),
            'overdue' => (int) ($row?->overdue_count ?? 0),
            'today' => (int) ($row?->today_count ?? 0),
            'upcoming' => (int) ($row?->upcoming_count ?? 0),
            'waiting' => (int) ($row?->waiting_count ?? 0),
            'mentions' => (int) ($row?->mentions_count ?? 0),
        ];
    }

    private function emptyMetrics(): array
    {
        return [
            'my_tasks' => 0,
            'createdToday' => 0,
            'notStarted' => 0,
            'inProgress' => 0,
            'dueThisWeek' => 0,
            'completedThisWeek' => 0,
            'attention' => 0,
            'overdue' => 0,
            'today' => 0,
            'upcoming' => 0,
            'waiting' => 0,
            'mentions' => 0,
        ];
    }


    /** @return list<string> */
    public function orderPhaseOptions(): array
    {
        return collect(OrderWorkflowSetupService::fixedStages())
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Reuse the authoritative seven-stage Order runtime on My Tasks. Historical
     * task rows may still reference retired phases such as "Order Intake", but
     * their counts and labels are folded into the matching current stage.
     *
     * @return list<array{id:int,filter_value:string,name:string,short_name:string,sequence:int,color:string,count:int,count_label:string}>
     */
    public function orderPhaseCards(User $user): array
    {
        $workspaceId = app(SetupContext::class)->workspaceId();
        $key = implode(':', [
            'flowtrack', 'my-work', 'stage-cards', 'v2',
            'workspace', max(1, $workspaceId),
            'user', (int) $user->id,
        ]);

        return Cache::remember(
            $key,
            now()->addSeconds(self::STAGE_CARD_CACHE_TTL_SECONDS),
            fn (): array => $this->buildOrderPhaseCards($user),
        );
    }

    /** @return list<array{id:int,filter_value:string,name:string,short_name:string,sequence:int,color:string,count:int,count_label:string}> */
    private function buildOrderPhaseCards(User $user): array
    {
        $preferredWorkflowId = OrderWorkflowSetupService::orderWorkflowQuery()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        $preferredPhases = $preferredWorkflowId
            ? DB::table('workflow_phases')
                ->where('workflow_template_id', $preferredWorkflowId)
                ->where('is_active', true)
                ->whereBetween('sequence', [1, count(OrderWorkflowSetupService::fixedStages())])
                ->get(['id', 'sequence', 'short_name', 'color'])
                ->keyBy(fn ($phase) => (int) $phase->sequence)
            : collect();

        // activeVisibleTaskQuery() already joins the current phase and saved
        // Task Pack item as my_work_active_phase/my_work_active_template. Reuse
        // those aliases and aggregate a tiny number of stage/key/status groups.
        $rawCounts = $this->personalTaskQuery($user, [])
            ->reorder()
            ->select([])
            ->select([
                'my_work_active_phase.name as phase_name',
                'my_work_active_phase.short_name as phase_short_name',
                'my_work_active_phase.sequence as phase_sequence',
                'my_work_active_template.automation_key as automation_key',
                'tasks.status as task_status',
            ])
            ->selectRaw('COUNT(tasks.id) AS aggregate')
            ->groupBy([
                'my_work_active_phase.name',
                'my_work_active_phase.short_name',
                'my_work_active_phase.sequence',
                'my_work_active_template.automation_key',
                'tasks.status',
            ])
            ->get();

        $countBySequence = $rawCounts
            ->groupBy(fn ($row) => OrderStageResolver::resolve(
                $row->phase_name,
                $row->phase_short_name,
                $row->phase_sequence !== null ? (int) $row->phase_sequence : null,
                $row->task_status,
                $row->automation_key,
            )['sequence'])
            ->map(fn ($rows): int => (int) $rows->sum(fn ($row) => (int) $row->aggregate));

        return collect(OrderWorkflowSetupService::fixedStages())
            ->values()
            ->map(function (array $fixed, int $index) use ($preferredPhases, $countBySequence): array {
                $sequence = $index + 1;
                $phase = $preferredPhases->get($sequence);
                $name = (string) $fixed['name'];

                return [
                    'id' => (int) ($phase?->id ?: $sequence),
                    'filter_value' => $name,
                    'name' => $name,
                    'short_name' => trim((string) ($phase?->short_name ?: $fixed['short'] ?: $name)),
                    'sequence' => $sequence,
                    'color' => \App\Support\MasterColor::normalize((string) ($phase?->color ?: $fixed['color'])) ?: '#2563EB',
                    'count' => (int) ($countBySequence->get($sequence, 0)),
                    'count_label' => 'Open tasks',
                ];
            })
            ->all();
    
    }

    /** @return list<int> */
    public function orderPhaseSourceIdsForName(string $phaseName): array
    {
        $expected = OrderStageResolver::sequenceFromName($phaseName);
        if (!$expected) return [];

        $workspaceId = app(SetupContext::class)->workspaceId();
        $key = 'flowtrack:my-work:stage-phase-map:v2:workspace:'.max(1, $workspaceId);

        $map = Cache::remember(
            $key,
            now()->addMinutes(self::STAGE_PHASE_MAP_CACHE_TTL_MINUTES),
            function (): array {
                $map = [];
                foreach (range(1, count(OrderWorkflowSetupService::fixedStages())) as $stageSequence) {
                    $map[$stageSequence] = [];
                }

                DB::table('workflow_phases')
                    ->get(['id', 'source_workflow_phase_id', 'name', 'short_name', 'sequence'])
                    ->each(function ($phase) use (&$map): void {
                        foreach (array_keys($map) as $stageSequence) {
                            if (! OrderStageResolver::matchesSequence(
                                (int) $stageSequence,
                                $phase->name,
                                $phase->short_name,
                                $phase->sequence !== null ? (int) $phase->sequence : null,
                            )) {
                                continue;
                            }

                            $map[$stageSequence][] = (int) $phase->id;
                            if ((int) ($phase->source_workflow_phase_id ?: 0) > 0) {
                                $map[$stageSequence][] = (int) $phase->source_workflow_phase_id;
                            }
                            break;
                        }
                    });

                foreach ($map as $stageSequence => $ids) {
                    $map[$stageSequence] = array_values(array_unique(array_filter(
                        array_map('intval', $ids),
                        fn (int $id): bool => $id > 0,
                    )));
                }

                return $map;
            },
        );

        return array_values($map[(int) $expected] ?? []);
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

    public function findPersonalVisibleTask(User $user, int $taskId): Task
    {
        return $this->personalTaskQuery($user, [])
            ->whereKey($taskId)
            ->firstOrFail();
    }

    /**
     * Count the same open task scope used by My Tasks. Every role, including
     * Admin and Super Admin, counts only the currently active Order task that is
     * assigned to that user. Keeping this in one service prevents the sidebar
     * badge and page results from drifting apart.
     */
    public function openTaskCount(User $user): int
    {
        $query = $this->activeVisibleTaskQuery($user);

        return $query
            ->reorder()
            ->count('tasks.id');
    }

    /**
     * Return the Orders that belong in My Tasks.
     *
     * For every role, an Order appears only while its CURRENT workflow task is
     * assigned to that user. Future assigned tasks stay hidden until sequencing
     * activates them. Order ownership, creator status, record-scope access, or
     * administrator privileges never add unrelated Orders to My Tasks.
     */
    public function personalOpenOrderIdsQuery(User $user): Builder
    {
        $query = $this->activeVisibleTaskQuery($user);

        return $query
            ->reorder()
            ->select('tasks.flow_job_id')
            ->distinct();
    }

    /**
     * Active Order-task scope used by My Tasks for EVERY role.
     *
     * My Tasks is a personal execution queue, not an operational overview.
     * Therefore the task must be assigned to the authenticated user regardless
     * of whether that user is a normal user, Admin, or Super Admin. Order
     * creator/owner access and broader record-scope permissions do not add tasks
     * to this page.
     *
     * The page also remains active-task-only: sibling, future, completed, and
     * stale workflow rows are excluded by the structural active-task resolver.
     */
    public function activeVisibleTaskQuery(User $user): Builder
    {
        // IMPORTANT: Do not infer "active" from the stored status alone.
        // Older/cloud Orders can contain stale sibling statuses (for example
        // more than one READY row) after workflow/task-pack changes. Order
        // Details does not trust those statuses; it resolves one structural
        // next task from the current phase and saved Task Pack. My Tasks must
        // use the same rule or the two screens drift apart.
        $query = Task::query()
            ->select('tasks.*')
            ->leftJoin('workflow_phases as my_work_active_phase', 'my_work_active_phase.id', '=', 'tasks.workflow_phase_id')
            ->leftJoin('task_pack_items as my_work_active_template', function ($join): void {
                $join->on('my_work_active_template.id', '=', 'tasks.task_pack_task_id')
                    ->on('my_work_active_template.task_pack_id', '=', 'my_work_active_phase.task_pack_id');
            })
            // This assignment constraint is deliberately unconditional. Admin
            // and Super Admin must not receive a broad My Tasks queue.
            ->where('tasks.assignee_id', $user->id);

        $query
            ->whereNull('tasks.completed_at')
            ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
            ->whereHas('job', function (Builder $job): void {
                $job
                    ->whereHas('client', fn (Builder $client) => $client->where('is_active', true))
                    ->whereNotIn('status', JobService::INACTIVE_STATUSES)
                    ->whereNull('completed_at')
                    ->whereRaw("LOWER(TRIM(flow_jobs.status)) != 'completed'")
                    ->whereColumn('flow_jobs.workflow_phase_id', 'tasks.workflow_phase_id');
            })
            // Match JobDetailPresenter::phaseTasks(): generated rows from an
            // obsolete Task Pack are not part of the live Order workflow even
            // when they still point at the same workflow_phase_id.
            ->where(function (Builder $validPhaseTask): void {
                $validPhaseTask
                    ->whereNull('tasks.task_pack_task_id')
                    ->orWhereNotNull('my_work_active_template.id');
            });

        $this->applyStructuralActiveTaskConstraint($query);

        return $query;
    }

    /**
     * Restrict an already-current-phase task query to exactly the task that
     * Order Details considers actionable.
     *
     * Normal path: first incomplete REQUIRED generated Task Pack item.
     * Conditional path: Sample Approval / QC Issue replaces its waiting
     * required blocker while that branch is active.
     * After generated required work: first manual task, then first activated
     * optional Task Pack item.
     *
     * The query is structural rather than status-driven, so stale READY / IN
     * PROGRESS values on cloud data cannot make sibling tasks appear active.
     */
    private function applyStructuralActiveTaskConstraint(Builder $query): void
    {
        $query->where(function (Builder $active): void {
            // 1. First incomplete required generated task in the current saved
            // Task Pack. Tie-break by task id exactly like Order Details.
            $active->where(function (Builder $required): void {
                $required
                    ->whereNotNull('my_work_active_template.id')
                    ->where(function (Builder $requiredFlag): void {
                        $requiredFlag
                            ->where('my_work_active_template.is_required', true)
                            ->orWhereNull('my_work_active_template.is_required');
                    })
                    ->whereNotExists(function ($earlier): void {
                        $earlier
                            ->selectRaw('1')
                            ->from('tasks as my_work_earlier_required')
                            ->join('task_pack_items as my_work_earlier_required_template', 'my_work_earlier_required_template.id', '=', 'my_work_earlier_required.task_pack_task_id')
                            ->whereColumn('my_work_earlier_required.flow_job_id', 'tasks.flow_job_id')
                            ->whereColumn('my_work_earlier_required.workflow_phase_id', 'tasks.workflow_phase_id')
                            ->whereColumn('my_work_earlier_required_template.task_pack_id', 'my_work_active_phase.task_pack_id')
                            ->whereNull('my_work_earlier_required.deleted_at')
                            ->whereNull('my_work_earlier_required.completed_at')
                            ->whereRaw("LOWER(TRIM(COALESCE(my_work_earlier_required.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
                            ->where(function ($requiredFlag): void {
                                $requiredFlag
                                    ->where('my_work_earlier_required_template.is_required', true)
                                    ->orWhereNull('my_work_earlier_required_template.is_required');
                            })
                            ->where(function ($position): void {
                                $position
                                    ->whereColumn('my_work_earlier_required_template.sort_order', '<', 'my_work_active_template.sort_order')
                                    ->orWhere(function ($tie): void {
                                        $tie
                                            ->whereColumn('my_work_earlier_required_template.sort_order', '=', 'my_work_active_template.sort_order')
                                            ->whereColumn('my_work_earlier_required.id', '<', 'tasks.id');
                                    });
                            });
                    })
                    // A waiting required blocker is not the active UI row while
                    // its explicit conditional child is available.
                    ->where(function (Builder $normalRequired): void {
                        $normalRequired
                            ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ('waiting for sample approval','waiting for qc issue resolution')")
                            ->orWhere(function (Builder $missingBranch): void {
                                $missingBranch
                                    ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) = 'waiting for sample approval'")
                                    ->whereNotExists(fn ($branch) => $this->sampleApprovalBranchExistsSubquery($branch));
                            })
                            ->orWhere(function (Builder $missingBranch): void {
                                $missingBranch
                                    ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) = 'waiting for qc issue resolution'")
                                    ->whereNotExists(fn ($branch) => $this->qcIssueBranchExistsSubquery($branch));
                            });
                    });
            });

            // 2. Explicit conditional branches used by Order Details.
            $active->orWhere(function (Builder $sample): void {
                $sample
                    ->whereNotNull('my_work_active_template.id')
                    ->where(function (Builder $optional): void {
                        $optional->where('my_work_active_template.is_required', false);
                    })
                    ->where(function (Builder $identity): void {
                        $identity
                            ->where('my_work_active_template.automation_key', 'ART_SAMPLE_APPROVAL')
                            ->orWhereRaw("LOWER(TRIM(tasks.title)) = 'sample approval (when required)'");
                    })
                    ->where(function (Builder $activated): void {
                        $activated
                            ->where('tasks.progress', '>', 0)
                            ->orWhereExists(fn ($documents) => $documents->selectRaw('1')->from('documents as my_work_sample_documents')->whereColumn('my_work_sample_documents.task_id', 'tasks.id'))
                            ->orWhereExists(fn ($links) => $links->selectRaw('1')->from('task_links as my_work_sample_links')->whereColumn('my_work_sample_links.task_id', 'tasks.id'))
                            ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ('','not start','not started','not ready','locked')");
                    })
                    ->whereExists(fn ($blocker) => $this->waitingRequiredBlockerSubquery($blocker, 'waiting for sample approval'));
            });

            $active->orWhere(function (Builder $qcIssue): void {
                $qcIssue
                    ->whereNotNull('my_work_active_template.id')
                    ->where('my_work_active_template.is_required', false)
                    ->where(function (Builder $identity): void {
                        $identity
                            ->where('my_work_active_template.automation_key', 'QC_ISSUE')
                            ->orWhereRaw("LOWER(TRIM(tasks.title)) = 'resolve qc issue (when needed)'");
                    })
                    ->whereExists(fn ($blocker) => $this->waitingRequiredBlockerSubquery($blocker, 'waiting for qc issue resolution'));
            });

            // 3. Manual Order tasks become active only after all required
            // generated work in the phase is complete, and only one manual row
            // is selected at a time.
            $active->orWhere(function (Builder $manual): void {
                $manual
                    ->whereNull('tasks.task_pack_task_id')
                    ->whereNotExists(fn ($required) => $this->openRequiredTaskSubquery($required))
                    ->whereNotExists(function ($earlierManual): void {
                        $earlierManual
                            ->selectRaw('1')
                            ->from('tasks as my_work_earlier_manual')
                            ->whereColumn('my_work_earlier_manual.flow_job_id', 'tasks.flow_job_id')
                            ->whereColumn('my_work_earlier_manual.workflow_phase_id', 'tasks.workflow_phase_id')
                            ->whereNull('my_work_earlier_manual.task_pack_task_id')
                            ->whereNull('my_work_earlier_manual.deleted_at')
                            ->whereNull('my_work_earlier_manual.completed_at')
                            ->whereRaw("LOWER(TRIM(COALESCE(my_work_earlier_manual.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
                            ->whereColumn('my_work_earlier_manual.id', '<', 'tasks.id');
                    });
            });

            // 4. Activated optional rows are a final fallback after required
            // and manual work, matching OrderDetailPresenter::nextTask().
            $active->orWhere(function (Builder $optional): void {
                $optional
                    ->whereNotNull('my_work_active_template.id')
                    ->where('my_work_active_template.is_required', false)
                    ->whereNotExists(fn ($required) => $this->openRequiredTaskSubquery($required))
                    ->whereNotExists(function ($manual): void {
                        $manual
                            ->selectRaw('1')
                            ->from('tasks as my_work_open_manual')
                            ->whereColumn('my_work_open_manual.flow_job_id', 'tasks.flow_job_id')
                            ->whereColumn('my_work_open_manual.workflow_phase_id', 'tasks.workflow_phase_id')
                            ->whereNull('my_work_open_manual.task_pack_task_id')
                            ->whereNull('my_work_open_manual.deleted_at')
                            ->whereNull('my_work_open_manual.completed_at')
                            ->whereRaw("LOWER(TRIM(COALESCE(my_work_open_manual.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')");
                    })
                    ->where(function (Builder $activated): void {
                        $activated
                            ->where('tasks.progress', '>', 0)
                            ->orWhereExists(fn ($documents) => $documents->selectRaw('1')->from('documents as my_work_optional_documents')->whereColumn('my_work_optional_documents.task_id', 'tasks.id'))
                            ->orWhereExists(fn ($links) => $links->selectRaw('1')->from('task_links as my_work_optional_links')->whereColumn('my_work_optional_links.task_id', 'tasks.id'))
                            ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ('','not start','not started','not ready','locked')");
                    })
                    ->whereNotExists(function ($earlierOptional): void {
                        $earlierOptional
                            ->selectRaw('1')
                            ->from('tasks as my_work_earlier_optional')
                            ->join('task_pack_items as my_work_earlier_optional_template', 'my_work_earlier_optional_template.id', '=', 'my_work_earlier_optional.task_pack_task_id')
                            ->whereColumn('my_work_earlier_optional.flow_job_id', 'tasks.flow_job_id')
                            ->whereColumn('my_work_earlier_optional.workflow_phase_id', 'tasks.workflow_phase_id')
                            ->whereColumn('my_work_earlier_optional_template.task_pack_id', 'my_work_active_phase.task_pack_id')
                            ->where('my_work_earlier_optional_template.is_required', false)
                            ->whereNull('my_work_earlier_optional.deleted_at')
                            ->whereNull('my_work_earlier_optional.completed_at')
                            ->whereRaw("LOWER(TRIM(COALESCE(my_work_earlier_optional.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
                            ->where(function ($activated): void {
                                $activated
                                    ->where('my_work_earlier_optional.progress', '>', 0)
                                    ->orWhereExists(fn ($documents) => $documents->selectRaw('1')->from('documents as my_work_earlier_optional_documents')->whereColumn('my_work_earlier_optional_documents.task_id', 'my_work_earlier_optional.id'))
                                    ->orWhereExists(fn ($links) => $links->selectRaw('1')->from('task_links as my_work_earlier_optional_links')->whereColumn('my_work_earlier_optional_links.task_id', 'my_work_earlier_optional.id'))
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(my_work_earlier_optional.status, ''))) NOT IN ('','not start','not started','not ready','locked')");
                            })
                            ->where(function ($position): void {
                                $position
                                    ->whereColumn('my_work_earlier_optional_template.sort_order', '<', 'my_work_active_template.sort_order')
                                    ->orWhere(function ($tie): void {
                                        $tie
                                            ->whereColumn('my_work_earlier_optional_template.sort_order', '=', 'my_work_active_template.sort_order')
                                            ->whereColumn('my_work_earlier_optional.id', '<', 'tasks.id');
                                    });
                            });
                    });
            });
        });
    }

    private function openRequiredTaskSubquery($query)
    {
        return $query
            ->selectRaw('1')
            ->from('tasks as my_work_open_required')
            ->join('task_pack_items as my_work_open_required_template', 'my_work_open_required_template.id', '=', 'my_work_open_required.task_pack_task_id')
            ->whereColumn('my_work_open_required.flow_job_id', 'tasks.flow_job_id')
            ->whereColumn('my_work_open_required.workflow_phase_id', 'tasks.workflow_phase_id')
            ->whereColumn('my_work_open_required_template.task_pack_id', 'my_work_active_phase.task_pack_id')
            ->whereNull('my_work_open_required.deleted_at')
            ->whereNull('my_work_open_required.completed_at')
            ->whereRaw("LOWER(TRIM(COALESCE(my_work_open_required.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
            ->where(function ($required): void {
                $required
                    ->where('my_work_open_required_template.is_required', true)
                    ->orWhereNull('my_work_open_required_template.is_required');
            });
    }

    private function waitingRequiredBlockerSubquery($query, string $waitingStatus)
    {
        return $this->openRequiredTaskSubquery($query)
            ->whereRaw('LOWER(TRIM(COALESCE(my_work_open_required.status, \'\'))) = ?', [$waitingStatus])
            ->whereNotExists(function ($earlier): void {
                $earlier
                    ->selectRaw('1')
                    ->from('tasks as my_work_blocker_earlier')
                    ->join('task_pack_items as my_work_blocker_earlier_template', 'my_work_blocker_earlier_template.id', '=', 'my_work_blocker_earlier.task_pack_task_id')
                    ->whereColumn('my_work_blocker_earlier.flow_job_id', 'my_work_open_required.flow_job_id')
                    ->whereColumn('my_work_blocker_earlier.workflow_phase_id', 'my_work_open_required.workflow_phase_id')
                    ->whereColumn('my_work_blocker_earlier_template.task_pack_id', 'my_work_open_required_template.task_pack_id')
                    ->whereNull('my_work_blocker_earlier.deleted_at')
                    ->whereNull('my_work_blocker_earlier.completed_at')
                    ->whereRaw("LOWER(TRIM(COALESCE(my_work_blocker_earlier.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
                    ->where(function ($required): void {
                        $required
                            ->where('my_work_blocker_earlier_template.is_required', true)
                            ->orWhereNull('my_work_blocker_earlier_template.is_required');
                    })
                    ->where(function ($position): void {
                        $position
                            ->whereColumn('my_work_blocker_earlier_template.sort_order', '<', 'my_work_open_required_template.sort_order')
                            ->orWhere(function ($tie): void {
                                $tie
                                    ->whereColumn('my_work_blocker_earlier_template.sort_order', '=', 'my_work_open_required_template.sort_order')
                                    ->whereColumn('my_work_blocker_earlier.id', '<', 'my_work_open_required.id');
                            });
                    });
            });
    }

    private function sampleApprovalBranchExistsSubquery($query)
    {
        return $query
            ->selectRaw('1')
            ->from('tasks as my_work_sample_branch')
            ->join('task_pack_items as my_work_sample_branch_template', 'my_work_sample_branch_template.id', '=', 'my_work_sample_branch.task_pack_task_id')
            ->whereColumn('my_work_sample_branch.flow_job_id', 'tasks.flow_job_id')
            ->whereColumn('my_work_sample_branch.workflow_phase_id', 'tasks.workflow_phase_id')
            ->whereColumn('my_work_sample_branch_template.task_pack_id', 'my_work_active_phase.task_pack_id')
            ->whereNull('my_work_sample_branch.deleted_at')
            ->whereNull('my_work_sample_branch.completed_at')
            ->whereRaw("LOWER(TRIM(COALESCE(my_work_sample_branch.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
            ->where(function ($identity): void {
                $identity
                    ->where('my_work_sample_branch_template.automation_key', 'ART_SAMPLE_APPROVAL')
                    ->orWhereRaw("LOWER(TRIM(my_work_sample_branch.title)) = 'sample approval (when required)'");
            })
            ->where(function ($activated): void {
                $activated
                    ->where('my_work_sample_branch.progress', '>', 0)
                    ->orWhereExists(fn ($documents) => $documents->selectRaw('1')->from('documents as my_work_sample_branch_documents')->whereColumn('my_work_sample_branch_documents.task_id', 'my_work_sample_branch.id'))
                    ->orWhereExists(fn ($links) => $links->selectRaw('1')->from('task_links as my_work_sample_branch_links')->whereColumn('my_work_sample_branch_links.task_id', 'my_work_sample_branch.id'))
                    ->orWhereRaw("LOWER(TRIM(COALESCE(my_work_sample_branch.status, ''))) NOT IN ('','not start','not started','not ready','locked')");
            });
    }

    private function qcIssueBranchExistsSubquery($query)
    {
        return $query
            ->selectRaw('1')
            ->from('tasks as my_work_qc_branch')
            ->join('task_pack_items as my_work_qc_branch_template', 'my_work_qc_branch_template.id', '=', 'my_work_qc_branch.task_pack_task_id')
            ->whereColumn('my_work_qc_branch.flow_job_id', 'tasks.flow_job_id')
            ->whereColumn('my_work_qc_branch.workflow_phase_id', 'tasks.workflow_phase_id')
            ->whereColumn('my_work_qc_branch_template.task_pack_id', 'my_work_active_phase.task_pack_id')
            ->whereNull('my_work_qc_branch.deleted_at')
            ->whereNull('my_work_qc_branch.completed_at')
            ->whereRaw("LOWER(TRIM(COALESCE(my_work_qc_branch.status, ''))) NOT IN ('completed','skipped','not applicable','n/a','cancelled','canceled')")
            ->where(function ($identity): void {
                $identity
                    ->where('my_work_qc_branch_template.automation_key', 'QC_ISSUE')
                    ->orWhereRaw("LOWER(TRIM(my_work_qc_branch.title)) = 'resolve qc issue (when needed)'");
            });
    }

    /**
     * Backwards-compatible alias for callers that explicitly request the
     * current active task assigned to the supplied user. activeVisibleTaskQuery()
     * now has that exact personal-assignment contract for every role.
     */
    public function activeAssignedTaskQuery(User $user): Builder
    {
        return $this->activeVisibleTaskQuery($user);
    }

    private function personalTaskQuery(
        User $user,
        array $filters,
    ): Builder
    {
        // My Tasks always contains only the currently active task rows that
        // are assigned to the authenticated user. The same rule applies to
        // normal users, Admin, and Super Admin.
        $query = $this->activeVisibleTaskQuery($user);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '' && $this->searchIsUsable($search)) {
            $like = '%'.$search.'%';
            $prefix = $search.'%';
            $looksLikeReference = preg_match('/^(JOB|TSK|TASK|ORD)[-0-9]/i', $search) === 1;
            $referencePrefixOnly = mb_strlen($search) < 3 && $this->looksLikeReferencePrefix($search);

            if ($referencePrefixOnly) {
                // A recognised two-character reference prefix is useful, but
                // it must never fan out into title/client/assignee contains
                // searches. Keep this tiny-input path index-friendly.
                $query->where(function (Builder $inner) use ($prefix) {
                    $inner->whereLike('tasks.task_number', $prefix)
                        ->orWhereHas('job', fn (Builder $job) => $job
                            ->whereLike('job_number', $prefix)
                            ->orWhereLike('order_number', $prefix));
                });
            } else {
                $query->where(function (Builder $inner) use ($like, $prefix, $looksLikeReference) {
                    $inner->whereLike('tasks.task_number', $looksLikeReference ? $prefix : $like)
                        ->orWhereLike('tasks.title', $like)
                        ->orWhereLike('tasks.attention_reason', $like)
                        ->orWhereHas('attentionFlag', fn (Builder $flag) => $flag->whereLike('name', $like))
                        ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->whereLike('name', $like))
                        ->orWhereHas('job', fn (Builder $job) => $job
                            ->whereLike('job_number', $looksLikeReference ? $prefix : $like)
                            ->orWhereLike('order_number', $looksLikeReference ? $prefix : $like)
                            ->orWhereLike('title', $like)
                            ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like)));
                });
            }
        }

        $phase = trim((string) ($filters['phase'] ?? ''));
        if ($phase !== '') {
            $sourcePhaseIds = $this->orderPhaseSourceIdsForName($phase);

            // Stage cards are one of seven canonical workflow stages. Resolve
            // historical/snapshot aliases once, then filter the indexed FK on
            // tasks directly. This avoids a correlated workflow_phases EXISTS
            // with LOWER(TRIM(...)) on every grouped-count and page query.
            if ($sourcePhaseIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('tasks.workflow_phase_id', $sourcePhaseIds);
            }
        }

        $statusFilter = trim((string) ($filters['status'] ?? ''));
        if ($statusFilter !== '') {
            $query->whereRaw('LOWER(TRIM(tasks.status)) = ?', [mb_strtolower($statusFilter)]);
        }

        $stageSupplierId = (int) ($filters['stage_supplier_id'] ?? 0);
        if ($stageSupplierId > 0) {
            $query->whereHas('job', fn (Builder $job) => $job
                ->whereHas('items', fn (Builder $items) => $items
                    ->where('is_removed', false)
                    ->where('supplier_id', $stageSupplierId)));
        }

        $stageAssigneeId = (int) ($filters['stage_assignee_id'] ?? 0);
        if ($stageAssigneeId > 0) {
            $query->where('tasks.assignee_id', $stageAssigneeId);
        }

        $this->applyQuickFilter($query, $user, (string) ($filters['quick'] ?? 'all'));

        return $query;
    }

    private function applyQuickFilter(Builder $query, User $user, string $quick): void
    {
        $workspace = app(WorkspaceSettingsService::class);
        $today = $workspace->localToday();
        $todayDate = $today->toDateString();
        $tomorrow = $today->addDay()->toDateString();
        $weekStartDate = $today->startOfWeek()->toDateString();
        $weekEndDate = $today->endOfWeek()->toDateString();
        [$weekStartUtc, $weekEndUtc] = $workspace->localWeekUtcBounds();
        $initialStatuses = self::INITIAL_TASK_STATUSES;
        $initialStatusPlaceholders = implode(',', array_fill(0, count($initialStatuses), '?'));

        match ($quick) {
            'createdToday' => $query->whereBetween('tasks.created_at', [
                $today->utc(),
                $today->endOfDay()->utc(),
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
            // Preserve old URLs/chips that may still exist in bookmarks.
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
        // Correlate the task directly to its own task.comment activity. The
        // activity metadata is written from MentionService::userIdsFromText(),
        // so a plain email/@ character does not qualify and a mention on one
        // task can never make sibling tasks from the same Order appear.
        return fn ($activity) => $activity
            ->selectRaw('1')
            ->from('activities as my_work_mention_activity')
            ->whereColumn('my_work_mention_activity.subject_id', 'tasks.id')
            ->where('my_work_mention_activity.subject_type', Task::class)
            ->where('my_work_mention_activity.event', 'task.comment')
            ->whereNotNull('my_work_mention_activity.meta')
            // Use the JSON array itself instead of relying on its serialized text.
            // This works consistently on MySQL and SQLite and keeps this filter
            // tied to the exact task.comment that contains a real parsed mention.
            ->whereJsonLength('my_work_mention_activity.meta->mention_user_ids', '>', 0);
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
        bool $parentCreatedByUser = false,
    ): array
    {
        $dueDate = $task->due_date?->format('Y-m-d');
        $completed = $task->completed_at !== null || \App\Support\BoardLaneResolver::isCompleted((string) $task->status);
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
            $task->getAttribute('my_work_phase_name'),
            $task->getAttribute('my_work_phase_short_name'),
            $task->getAttribute('my_work_phase_sequence') !== null ? (int) $task->getAttribute('my_work_phase_sequence') : null,
            (string) $task->status,
            $task->getAttribute('my_work_automation_key'),
        );

        return [
            'id' => (int) $task->id,
            'number' => (string) $task->task_number,
            'title' => (string) $task->title,
            'phase' => (string) $taskStage['short_name'],
            'phaseSequence' => (int) $taskStage['sequence'],
            'phaseColor' => $task->getAttribute('my_work_phase_color') ?: $taskStage['color'],
            'taskColor' => \App\Support\MasterColor::normalize((string) $task->getAttribute('my_work_task_color'))
                ?: \App\Support\MasterColor::normalize((string) $task->getAttribute('my_work_phase_color'))
                ?: '#2563EB',
            'assignee' => (string) ($task->getAttribute('my_work_assignee_name') ?: 'Unassigned'),
            'assigneeId' => $task->assignee_id ? (int) $task->assignee_id : null,
            'assigneeAvatar' => app(\App\Services\StoredAssetUrlService::class)->profileImageUrl(
                (int) ($task->assignee_id ?? 0),
                $task->getAttribute('my_work_assignee_profile_image_path'),
            ),
            'due' => $dueLabel,
            'dueValue' => $dueDate ?: '',
            'dueDisplay' => $task->due_date?->format('M j, Y') ?? 'Set due date',
            'dueTone' => $dueTone,
            'status' => (string) $task->status,
            'statusColor' => $statusColor,
            'flag' => $flag,
            'flagTone' => $this->tone($flag),
            'flagColor' => $flagColor,
            'updated' => $updatedAt?->diffForHumans() ?: '—',
            'version' => (string) $task->getRawOriginal('updated_at'),
            'canEdit' => $access->canEditVisibleTask($user, $task),
            // Tasks on this page already came through TaskService::visibleQuery(),
            // so assignment authorization can be decided without another scope
            // query for every rendered row.
            'canAssign' => $access->isAdministrator($user)
                || $parentCreatedByUser
                || $access->can($user, 'tasks', 'assign'),
            'route' => route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]),
        ];
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

    public function searchIsUsable(string $search): bool
    {
        $search = trim($search);
        $length = mb_strlen($search);

        if ($length >= 3) return true;
        if ($length < 2) return false;

        // Permit known two-character reference prefixes without permitting
        // arbitrary two-character global contains searches.
        return $this->looksLikeReferencePrefix($search);
    }

    private function looksLikeReferencePrefix(string $search): bool
    {
        return preg_match('/^(JO|TS|TA|OR)$/i', trim($search)) === 1;
    }
}
