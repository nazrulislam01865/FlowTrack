<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, FlowAuthorizationService $authorization): Response
    {
        $user = $request->user();
        abort_unless($authorization->allows($user, 'reports', 'view'), 403);

        $jobs = $authorization->applyJobVisibility(FlowJob::query(), $user, 'reports');
        $tasks = FlowTask::query()->whereHas(
            'job',
            fn (Builder $job) => $authorization->applyJobVisibility($job, $user, 'reports'),
        );

        $completedJobs = (clone $jobs)->where('status', 'completed');
        $completedTasks = (clone $tasks)->whereNotNull('completed_at');
        $totalJobs = (clone $jobs)->count();
        $confirmedJobs = (clone $jobs)->whereNotNull('order_code')->count();

        $artworkCycleDays = $this->averagePhaseDurationDays($user, 'Artwork', $authorization);
        $shipmentHistories = $this->completedPhaseHistories($user, 'Shipment', $authorization);
        $onTimeShipments = $shipmentHistories->filter(fn (FlowJobPhaseHistory $history) =>
            $history->job?->delivery_date
            && $history->completed_at?->toDateString() <= $history->job->delivery_date->toDateString()
        )->count();

        $canViewFinancials = $authorization->allows($user, 'invoice', 'view')
            && $authorization->canViewSensitiveField($user, 'invoice_amount');

        $clients = $canViewFinancials
            ? Client::query()
                ->where('workspace_id', $user->currentWorkspaceId())
                ->where('outstanding_balance', '>', 0)
                ->whereHas('jobs', fn (Builder $job) => $authorization->applyJobVisibility($job, $user, 'reports'))
                ->with(['jobs' => fn (Builder $query) => $authorization
                    ->applyJobVisibility($query, $user, 'reports')
                    ->where('status', '!=', 'completed')
                    ->orderBy('delivery_date')])
                ->get()
            : collect();

        return Inertia::render('flowtrack/Reports/Index', [
            'metrics' => [
                'quotationConversion' => $this->percentage($confirmedJobs, $totalJobs),
                'onTimeJobs' => $this->percentage((clone $completedJobs)->whereColumn('updated_at', '<=', 'delivery_date')->count(), (clone $completedJobs)->count()),
                'taskCompletion' => $this->percentage((clone $completedTasks)->whereColumn('completed_at', '<=', 'due_date')->count(), (clone $completedTasks)->count()),
                'artworkCycle' => round($artworkCycleDays, 1),
                'shipmentOnTime' => $this->percentage($onTimeShipments, $shipmentHistories->count()),
                'openReceivables' => $canViewFinancials ? (float) $clients->sum('outstanding_balance') : null,
                'financialVisible' => $canViewFinancials,
            ],
            'phaseData' => (clone $jobs)
                ->join('workflow_phases', 'flow_jobs.current_phase_id', '=', 'workflow_phases.id')
                ->selectRaw('workflow_phases.short_name as label, count(*) as value')
                ->groupBy('workflow_phases.short_name')
                ->orderBy('workflow_phases.short_name')
                ->get(),
            'workload' => (clone $tasks)
                ->whereNull('completed_at')
                ->join('users', 'flow_tasks.assignee_id', '=', 'users.id')
                ->selectRaw('users.name as label, count(*) as value')
                ->groupBy('users.name')
                ->orderByDesc('value')
                ->limit(10)
                ->get(),
            'delivery' => [
                'delivered' => (clone $completedJobs)->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count(),
                'onTime' => (clone $completedJobs)->whereColumn('updated_at', '<=', 'delivery_date')->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count(),
                'supplierDelay' => (clone $jobs)->where('health', 'delayed')->count(),
                'clientDelay' => (clone $jobs)->where('health', 'needs_attention')->count(),
            ],
            'ageing' => $canViewFinancials ? $this->receivableAgeing($clients) : [],
        ]);
    }

    private function completedPhaseHistories(User $user, string $shortName, FlowAuthorizationService $authorization): Collection
    {
        return FlowJobPhaseHistory::query()
            ->whereNotNull('completed_at')
            ->whereHas('job', fn (Builder $job) => $authorization->applyJobVisibility($job, $user, 'reports'))
            ->whereHas('phase', fn (Builder $phase) => $phase->where('short_name', $shortName))
            ->with(['job:id,delivery_date', 'phase:id,short_name'])
            ->get();
    }

    private function averagePhaseDurationDays(User $user, string $shortName, FlowAuthorizationService $authorization): float
    {
        $durations = $this->completedPhaseHistories($user, $shortName, $authorization)
            ->filter(fn (FlowJobPhaseHistory $history) => $history->entered_at && $history->completed_at)
            ->map(fn (FlowJobPhaseHistory $history) => $history->entered_at->diffInMinutes($history->completed_at) / 1440);

        return (float) ($durations->average() ?? 0);
    }

    private function receivableAgeing(Collection $clients): array
    {
        $buckets = [
            'Not due' => 0.0,
            '1–15 days' => 0.0,
            '16–30 days' => 0.0,
            '30+ days' => 0.0,
        ];

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

        return collect($buckets)
            ->map(fn (float $value, string $label) => ['label' => $label, 'value' => round($value, 2)])
            ->values()
            ->all();
    }

    private function percentage(int $part, int $total): int
    {
        return $total > 0 ? (int) round(($part / $total) * 100) : 0;
    }
}
