<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Application\Support\FlowTrackMetricsCache;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\Role;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OperationalMetricsService
{
    public function __construct(
        private readonly FlowAuthorizationService $authorization,
        private readonly FlowTrackMetricsCache $metricsCache,
    ) {
    }

    public function forUser(User $user): array
    {
        return $this->metricsCache->remember($user, fn (): array => $this->calculateForUser($user));
    }

    public function forPage(User $user, string $page): array
    {
        $page = strtolower(trim($page));
        $section = match ($page) {
            'dashboard' => 'dashboard',
            'mywork' => 'myWork',
            'reports' => 'reports',
            'workflowsetup' => 'workflow',
            'taskpacksetup' => 'taskPacks',
            'masterdata' => 'masterData',
            'setup' => 'administration',
            default => null,
        };

        if (! $section) {
            return ['asOf' => $this->asOf()];
        }

        return $this->metricsCache->rememberSection($user, $section, function () use ($user, $section): array {
            $workspaceId = $user->currentWorkspaceId();
            $jobs = $this->authorization->applyJobVisibility(FlowJob::query(), $user);
            $tasks = $this->authorization->applyTaskVisibility(FlowTask::query(), $user);
            $canViewFinancials = $this->authorization->allows($user, 'invoice', 'view')
                && $this->authorization->canViewSensitiveField($user, 'invoice_amount');

            return [
                'asOf' => $this->asOf(),
                $section => match ($section) {
                    'dashboard' => $this->dashboardMetrics($workspaceId, $jobs, $tasks, $canViewFinancials),
                    'myWork' => $this->myWorkMetrics($tasks),
                    'workflow' => $this->workflowMetrics($workspaceId),
                    'taskPacks' => $this->taskPackMetrics($workspaceId),
                    'masterData' => $this->masterDataMetrics($workspaceId),
                    'administration' => $this->administrationMetrics($workspaceId),
                    'reports' => $this->reportMetrics($user, $jobs, $tasks, $canViewFinancials),
                    default => [],
                },
            ];
        });
    }

    private function asOf(): array
    {
        return [
            'date' => today()->toDateString(),
            'displayDate' => now()->isoFormat('dddd, MMMM D'),
            'weekStart' => now()->startOfWeek()->toDateString(),
            'weekEnd' => now()->endOfWeek()->toDateString(),
        ];
    }

    private function blockedTaskConstraint(): \Closure
    {
        return function (Builder $query): void {
            $query->where('attention_required', true)
                ->orWhereNotNull('blocker_reason')
                ->orWhereHas('status', fn (Builder $status) => $status
                    ->whereRaw('LOWER(name) = ?', ['blocked'])
                    ->orWhereRaw('LOWER(code) IN (?, ?)', ['blk', 'blocked']));
        };
    }

    private function waitingTaskConstraint(): \Closure
    {
        return function (Builder $query): void {
            $query->whereRaw('LOWER(name) LIKE ?', ['waiting%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%review%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['wait%']);
        };
    }

    private function dashboardMetrics(int $workspaceId, Builder $jobs, Builder $tasks, bool $canViewFinancials): array
    {
        $blockedTaskConstraint = $this->blockedTaskConstraint();

        return [
            'activeJobs' => (clone $jobs)->where('status', '!=', 'completed')->count(),
            'needsAttention' => (clone $jobs)->where(function (Builder $query): void {
                $query->where('attention_required', true)
                    ->orWhereIn('health', ['needs_attention', 'at_risk', 'delayed', 'blocked']);
            })->where('status', '!=', 'completed')->count(),
            'overdueTasks' => (clone $tasks)->whereDate('due_date', '<', today())->whereNull('completed_at')->count(),
            'blockedTasks' => (clone $tasks)->where($blockedTaskConstraint)->whereNull('completed_at')->count(),
            'shippingNow' => (clone $jobs)->where('status', '!=', 'completed')->whereHas('currentPhase', function (Builder $phase): void {
                $phase->whereRaw('LOWER(name) LIKE ?', ['%ship%'])
                    ->orWhereRaw('LOWER(short_name) LIKE ?', ['%ship%']);
            })->count(),
            'outstanding' => $canViewFinancials
                ? (float) Client::query()->where('workspace_id', $workspaceId)
                    ->whereIn('id', (clone $jobs)->select('client_id'))
                    ->sum('outstanding_balance')
                : null,
            'canViewFinancials' => $canViewFinancials,
        ];
    }

    private function myWorkMetrics(Builder $tasks): array
    {
        $blockedTaskConstraint = $this->blockedTaskConstraint();
        $waitingTaskConstraint = $this->waitingTaskConstraint();

        return [
            'dueToday' => (clone $tasks)->whereDate('due_date', today())->whereNull('completed_at')->count(),
            'overdue' => (clone $tasks)->whereDate('due_date', '<', today())->whereNull('completed_at')->count(),
            'blockedAttention' => (clone $tasks)->where($blockedTaskConstraint)->whereNull('completed_at')->count(),
            'waitingReview' => (clone $tasks)->whereHas('status', $waitingTaskConstraint)->whereNull('completed_at')->count(),
            'completedThisWeek' => (clone $tasks)->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'upcoming' => (clone $tasks)->whereDate('due_date', '>', today())->whereNull('completed_at')->count(),
        ];
    }

    private function workflowMetrics(int $workspaceId): array
    {
        return [
            'templates' => WorkflowTemplate::query()->where('workspace_id', $workspaceId)->count(),
            'activeTemplates' => WorkflowTemplate::query()->where('workspace_id', $workspaceId)->where('is_active', true)->count(),
            'configuredPhases' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->count(),
            'allowedStartingStages' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->where('allow_job_start', true)->where('is_active', true)->count(),
            'automaticTransitions' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->where('auto_advance_on_ready', true)->where('is_active', true)->count(),
        ];
    }

    private function taskPackMetrics(int $workspaceId): array
    {
        return [
            'total' => TaskPack::query()->where('workspace_id', $workspaceId)->count(),
            'active' => TaskPack::query()->where('workspace_id', $workspaceId)->where('is_active', true)->count(),
            'configuredTasks' => TaskPackItem::query()->whereHas('pack', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->count(),
            'mappedPhases' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->whereNotNull('task_pack_id')->count(),
        ];
    }

    private function masterDataMetrics(int $workspaceId): array
    {
        return [
            'categories' => count(MasterDataType::cases()),
            'totalRecords' => MasterRecord::query()->where('workspace_id', $workspaceId)->count(),
            'activeRecords' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('status', 'active')->count(),
            'inactiveRecords' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('status', '!=', 'active')->count(),
        ];
    }

    private function administrationMetrics(int $workspaceId): array
    {
        return [
            'activeUsers' => WorkspaceMembership::query()->where('workspace_id', $workspaceId)->where('status', 'active')->count(),
            'operationalRoles' => Role::query()->where('workspace_id', $workspaceId)->where('is_active', true)->count(),
            'departments' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active')->count(),
            'notificationRules' => count(config('flowtrack.notification_rules', [])),
        ];
    }

    private function calculateForUser(User $user): array
    {
        $workspaceId = $user->currentWorkspaceId();
        $jobs = $this->authorization->applyJobVisibility(FlowJob::query(), $user);
        $tasks = $this->authorization->applyTaskVisibility(FlowTask::query(), $user);
        $canViewFinancials = $this->authorization->allows($user, 'invoice', 'view')
            && $this->authorization->canViewSensitiveField($user, 'invoice_amount');

        $blockedTaskConstraint = function (Builder $query): void {
            $query->where('attention_required', true)
                ->orWhereNotNull('blocker_reason')
                ->orWhereHas('status', fn (Builder $status) => $status
                    ->whereRaw('LOWER(name) = ?', ['blocked'])
                    ->orWhereRaw('LOWER(code) IN (?, ?)', ['blk', 'blocked']));
        };

        $waitingTaskConstraint = function (Builder $query): void {
            $query->whereRaw('LOWER(name) LIKE ?', ['waiting%'])
                ->orWhereRaw('LOWER(name) LIKE ?', ['%review%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['wait%']);
        };

        $dashboard = [
            'activeJobs' => (clone $jobs)->where('status', '!=', 'completed')->count(),
            'needsAttention' => (clone $jobs)->where(function (Builder $query): void {
                $query->where('attention_required', true)
                    ->orWhereIn('health', ['needs_attention', 'at_risk', 'delayed', 'blocked']);
            })->where('status', '!=', 'completed')->count(),
            'overdueTasks' => (clone $tasks)->whereDate('due_date', '<', today())->whereNull('completed_at')->count(),
            'blockedTasks' => (clone $tasks)->where($blockedTaskConstraint)->whereNull('completed_at')->count(),
            'shippingNow' => (clone $jobs)->where('status', '!=', 'completed')->whereHas('currentPhase', function (Builder $phase): void {
                $phase->whereRaw('LOWER(name) LIKE ?', ['%ship%'])
                    ->orWhereRaw('LOWER(short_name) LIKE ?', ['%ship%']);
            })->count(),
            'outstanding' => $canViewFinancials
                ? (float) Client::query()->where('workspace_id', $workspaceId)
                    ->whereIn('id', (clone $jobs)->select('client_id'))
                    ->sum('outstanding_balance')
                : null,
            'canViewFinancials' => $canViewFinancials,
        ];

        $myWork = [
            'dueToday' => (clone $tasks)->whereDate('due_date', today())->whereNull('completed_at')->count(),
            'overdue' => (clone $tasks)->whereDate('due_date', '<', today())->whereNull('completed_at')->count(),
            'blockedAttention' => (clone $tasks)->where($blockedTaskConstraint)->whereNull('completed_at')->count(),
            'waitingReview' => (clone $tasks)->whereHas('status', $waitingTaskConstraint)->whereNull('completed_at')->count(),
            'completedThisWeek' => (clone $tasks)->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'upcoming' => (clone $tasks)->whereDate('due_date', '>', today())->whereNull('completed_at')->count(),
        ];

        $workflow = [
            'templates' => WorkflowTemplate::query()->where('workspace_id', $workspaceId)->count(),
            'activeTemplates' => WorkflowTemplate::query()->where('workspace_id', $workspaceId)->where('is_active', true)->count(),
            'configuredPhases' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->count(),
            'allowedStartingStages' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->where('allow_job_start', true)->where('is_active', true)->count(),
            'automaticTransitions' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->where('auto_advance_on_ready', true)->where('is_active', true)->count(),
        ];

        $taskPacks = [
            'total' => TaskPack::query()->where('workspace_id', $workspaceId)->count(),
            'active' => TaskPack::query()->where('workspace_id', $workspaceId)->where('is_active', true)->count(),
            'configuredTasks' => TaskPackItem::query()->whereHas('pack', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->count(),
            'mappedPhases' => WorkflowPhase::query()->whereHas('workflow', fn (Builder $query) => $query->where('workspace_id', $workspaceId))->whereNotNull('task_pack_id')->count(),
        ];

        $masterData = [
            'categories' => count(MasterDataType::cases()),
            'totalRecords' => MasterRecord::query()->where('workspace_id', $workspaceId)->count(),
            'activeRecords' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('status', 'active')->count(),
            'inactiveRecords' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('status', '!=', 'active')->count(),
        ];

        $administration = [
            'activeUsers' => WorkspaceMembership::query()->where('workspace_id', $workspaceId)->where('status', 'active')->count(),
            'operationalRoles' => Role::query()->where('workspace_id', $workspaceId)->where('is_active', true)->count(),
            'departments' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active')->count(),
            'notificationRules' => count(config('flowtrack.notification_rules', [])),
        ];

        return [
            'asOf' => [
                'date' => today()->toDateString(),
                'displayDate' => now()->isoFormat('dddd, MMMM D'),
                'weekStart' => now()->startOfWeek()->toDateString(),
                'weekEnd' => now()->endOfWeek()->toDateString(),
            ],
            'dashboard' => $dashboard,
            'myWork' => $myWork,
            'workflow' => $workflow,
            'taskPacks' => $taskPacks,
            'masterData' => $masterData,
            'administration' => $administration,
            'reports' => $this->reportMetrics($user, $jobs, $tasks, $canViewFinancials),
        ];
    }

    private function reportMetrics(User $user, Builder $jobs, Builder $tasks, bool $canViewFinancials): array
    {
        $completedJobs = (clone $jobs)->where('status', 'completed');
        $completedTasks = (clone $tasks)->whereNotNull('completed_at');
        $totalJobs = (clone $jobs)->count();
        $confirmedJobs = (clone $jobs)->whereNotNull('order_code')->count();
        $artworkHistories = $this->completedPhaseHistories($user, 'Artwork');
        $shipmentHistories = $this->completedPhaseHistories($user, 'Shipment');
        $artworkDurations = $artworkHistories
            ->filter(fn (FlowJobPhaseHistory $history) => $history->entered_at && $history->completed_at)
            ->map(fn (FlowJobPhaseHistory $history) => $history->entered_at->diffInMinutes($history->completed_at) / 1440);
        $onTimeShipments = $shipmentHistories->filter(fn (FlowJobPhaseHistory $history) =>
            $history->job?->delivery_date
            && $history->completed_at?->toDateString() <= $history->job->delivery_date->toDateString()
        )->count();

        $clients = $canViewFinancials
            ? Client::query()
                ->where('workspace_id', $user->currentWorkspaceId())
                ->where('outstanding_balance', '>', 0)
                ->whereHas('jobs', fn (Builder $job) => $this->authorization->applyJobVisibility($job, $user, 'reports'))
                ->with(['jobs' => fn (Builder $query) => $this->authorization
                    ->applyJobVisibility($query, $user, 'reports')
                    ->where('status', '!=', 'completed')
                    ->orderBy('delivery_date')])
                ->get()
            : collect();

        return [
            'quotationConversion' => $this->percentage($confirmedJobs, $totalJobs),
            'onTimeJobs' => $this->percentage((clone $completedJobs)->whereColumn('updated_at', '<=', 'delivery_date')->count(), (clone $completedJobs)->count()),
            'taskCompletion' => $this->percentage((clone $completedTasks)->whereColumn('completed_at', '<=', 'due_date')->count(), (clone $completedTasks)->count()),
            'artworkCycle' => round((float) ($artworkDurations->average() ?? 0), 1),
            'shipmentOnTime' => $this->percentage($onTimeShipments, $shipmentHistories->count()),
            'openReceivables' => $canViewFinancials ? (float) $clients->sum('outstanding_balance') : null,
            'financialVisible' => $canViewFinancials,
            'deliveredThisMonth' => (clone $completedJobs)->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count(),
            'deliveredOnTimeThisMonth' => (clone $completedJobs)->whereColumn('updated_at', '<=', 'delivery_date')->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count(),
            'supplierDelays' => (clone $jobs)->where('health', 'delayed')->count(),
            'clientDependencies' => (clone $jobs)->where('health', 'needs_attention')->count(),
            'ageing' => $canViewFinancials ? $this->receivableAgeing($clients) : [],
        ];
    }

    private function completedPhaseHistories(User $user, string $shortName): Collection
    {
        return FlowJobPhaseHistory::query()
            ->whereNotNull('completed_at')
            ->whereHas('job', fn (Builder $job) => $this->authorization->applyJobVisibility($job, $user, 'reports'))
            ->whereHas('phase', fn (Builder $phase) => $phase->where('short_name', $shortName))
            ->with(['job:id,delivery_date', 'phase:id,short_name'])
            ->get();
    }

    private function receivableAgeing(Collection $clients): array
    {
        $buckets = ['Not due' => 0.0, '1–15 days' => 0.0, '16–30 days' => 0.0, '30+ days' => 0.0];

        foreach ($clients as $client) {
            $oldestDelivery = $client->jobs->pluck('delivery_date')->filter()->sort()->first();
            $daysOverdue = $oldestDelivery ? today()->diffInDays($oldestDelivery, false) * -1 : 0;
            $bucket = match (true) {
                $daysOverdue <= 0 => 'Not due',
                $daysOverdue <= 15 => '1–15 days',
                $daysOverdue <= 30 => '16–30 days',
                default => '30+ days',
            };
            $buckets[$bucket] += (float) $client->outstanding_balance;
        }

        return collect($buckets)->map(fn (float $value, string $label) => ['label' => $label, 'value' => round($value, 2)])->values()->all();
    }

    private function percentage(int $part, int $total): int
    {
        return $total > 0 ? (int) round(($part / $total) * 100) : 0;
    }
}
