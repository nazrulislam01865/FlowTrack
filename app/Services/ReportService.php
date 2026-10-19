<?php

namespace App\Services;

use App\Models\FlowJobPhaseHistory;
use App\Models\User;
use App\Models\WorkflowPhase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function forget(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;
        Cache::forget($this->kpiCacheKey($userId));
    }

    /**
     * Backwards-compatible aggregate used by tests and non-Livewire callers.
     * The Reports Livewire component now requests these sections independently.
     */
    public function data(User $user): array
    {
        return [
            'phase' => $this->phase($user),
            'workload' => $this->workload($user),
            'kpis' => $this->kpis($user),
        ];
    }

    public function phase(User $user)
    {
        $this->authorize($user);

        $rows = app(JobService::class)->activeQuery($user)
            ->reorder()
            ->selectRaw('coalesce(source_workflow_phase_id, workflow_phase_id) as phase_key, count(*) total')
            ->groupByRaw('coalesce(source_workflow_phase_id, workflow_phase_id)')
            ->get();

        $phaseKeys = $rows->pluck('phase_key')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $phases = WorkflowPhase::query()
            ->whereIn('id', $phaseKeys)
            ->get(['id', 'name', 'short_name', 'sequence'])
            ->keyBy('id');

        $missingKeys = $phaseKeys->diff($phases->keys());
        $snapshotPhases = $missingKeys->isEmpty()
            ? collect()
            : WorkflowPhase::query()
                ->whereIn('source_workflow_phase_id', $missingKeys)
                ->orderBy('id')
                ->get(['id', 'source_workflow_phase_id', 'name', 'short_name', 'sequence'])
                ->unique('source_workflow_phase_id')
                ->keyBy('source_workflow_phase_id');

        foreach ($rows as $row) {
            $key = (int) $row->phase_key;
            $row->setRelation('phase', $phases->get($key) ?: $snapshotPhases->get($key));
        }

        return $rows;
    }

    public function workload(User $user)
    {
        $this->authorize($user);
        $access = app(AccessControlService::class);
        $query = User::query()
            ->where('is_active', true)
            ->select(['users.id', 'users.name']);

        if (!$access->isAdministrator($user) && $access->scope($user, 'tasks') !== 'all_records') {
            $query->whereKey($user->id);
        }

        return $query
            ->withCount(['assignedTasks as open_tasks_count' => fn ($tasks) => $tasks
                ->whereNull('completed_at')
                ->whereHas('job', fn ($job) => $job
                    ->whereHas('client', fn ($client) => $client->where('is_active', true))
                    ->whereNull('completed_at')->whereNotIn('status', JobService::INACTIVE_STATUSES))])
            ->orderByDesc('open_tasks_count')
            ->limit(8)
            ->get();
    }

    public function kpis(User $user): array
    {
        $this->authorize($user);

        return Cache::remember($this->kpiCacheKey($user->id), now()->addSeconds(15), function () use ($user) {
            $jobs = app(JobService::class)->visibleQuery($user)
                ->whereHas('client', fn ($client) => $client->where('is_active', true));
            $activeJobs = app(JobService::class)->activeQuery($user);
            $tasks = app(TaskService::class)->visibleQuery($user)
                ->whereHas('job', fn ($job) => $job->whereHas('client', fn ($client) => $client->where('is_active', true)));

            $jobMetrics = (clone $jobs)
                ->reorder()
                ->selectRaw('sum(case when flow_jobs.completed_at is not null then 1 else 0 end) as completed_jobs')
                ->selectRaw('sum(case when flow_jobs.completed_at is not null and flow_jobs.delivery_date is not null and date(flow_jobs.completed_at) <= flow_jobs.delivery_date then 1 else 0 end) as on_time_jobs')
                ->first();

            $taskMetrics = (clone $tasks)
                ->reorder()
                ->selectRaw('count(*) as task_total')
                ->selectRaw('sum(case when tasks.completed_at is not null then 1 else 0 end) as task_done')
                ->selectRaw("sum(case when tasks.completed_at is null and tasks.due_date < ? and exists (select 1 from flow_jobs where flow_jobs.id = tasks.flow_job_id and flow_jobs.deleted_at is null and flow_jobs.completed_at is null and flow_jobs.status not in ('Inactive','Cancelled')) then 1 else 0 end) as overdue_tasks", [app(WorkspaceSettingsService::class)->localToday()->format('Y-m-d')])
                ->first();

            $completedJobs = (int) ($jobMetrics?->completed_jobs ?? 0);
            $taskTotal = (int) ($taskMetrics?->task_total ?? 0);
            $taskDone = (int) ($taskMetrics?->task_done ?? 0);

            return [
                'active_jobs' => (clone $activeJobs)->count(),
                'completed_jobs' => $completedJobs,
                'overdue_tasks' => (int) ($taskMetrics?->overdue_tasks ?? 0),
                'task_done' => $taskDone,
                'task_completion' => $taskTotal > 0 ? (int) round($taskDone / $taskTotal * 100) : 0,
                'on_time' => $completedJobs > 0 ? (int) round(((int) ($jobMetrics?->on_time_jobs ?? 0)) / $completedJobs * 100) : 0,
                'avg_artwork_cycle' => $this->averagePhaseCycleDays($jobs, 'artwork'),
                'shipment_on_time' => $this->phaseOnTimePercentage($jobs, 'ship'),
            ];
        });
    }

    private function kpiCacheKey(int $userId): string
    {
        return 'flowtrack:reports:kpis:v3:clients-'.app(ClientService::class)->lifecycleVersion().':data-'.app(WorkspaceRefreshService::class)->version().':user:'.$userId;
    }

    private function authorize(User $user): void
    {
        abort_unless(app(AccessControlService::class)->can($user, 'reports', 'view'), 403);
    }

    private function averagePhaseCycleDays(Builder $visibleJobs, string $phaseNeedle): float
    {
        $query = $this->phaseHistoryQuery($visibleJobs, $phaseNeedle)
            ->whereNotNull('entered_at')
            ->whereNotNull('completed_at');

        $driver = DB::connection()->getDriverName();
        $expression = $driver === 'sqlite'
            ? 'avg(julianday(completed_at) - julianday(entered_at)) as average_days'
            : 'avg(timestampdiff(minute, entered_at, completed_at)) / 1440 as average_days';

        $average = $query->selectRaw($expression)->value('average_days');

        return round(max(0, (float) ($average ?? 0)), 1);
    }

    private function phaseOnTimePercentage(Builder $visibleJobs, string $phaseNeedle): int
    {
        $row = $this->phaseHistoryQuery($visibleJobs, $phaseNeedle)
            ->whereNotNull('target_date')
            ->whereNotNull('completed_at')
            ->selectRaw('count(*) as completed_total')
            ->selectRaw('sum(case when date(completed_at) <= target_date then 1 else 0 end) as completed_on_time')
            ->first();

        $total = (int) ($row?->completed_total ?? 0);

        return $total > 0
            ? (int) round(((int) ($row?->completed_on_time ?? 0)) / $total * 100)
            : 0;
    }

    private function phaseHistoryQuery(Builder $visibleJobs, string $phaseNeedle): Builder
    {
        $needle = '%'.mb_strtolower($phaseNeedle).'%';

        return FlowJobPhaseHistory::query()
            ->whereIn('flow_job_id', (clone $visibleJobs)->reorder()->select('flow_jobs.id'))
            ->whereHas('phase', function ($query) use ($needle) {
                $query->where(function ($phase) use ($needle) {
                    $phase->whereRaw('lower(name) like ?', [$needle])
                        ->orWhereRaw('lower(short_name) like ?', [$needle]);
                });
            });
    }
}
