<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardTeamPerformanceQuery;
use App\Services\AccessControlService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class TeamPanel extends Panel
{
    protected function heading(): string { return 'Team performance & workload'; }
    protected function placeholderHeight(): int { return 280; }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        abort_unless(app(AccessControlService::class)->can($actor, 'reports', 'view'), 403);
        try {
            $service = app(DashboardTeamPerformanceQuery::class);
            $period = $service->dashboardPeriod($actor, $filters->rangeDays);
            $rows = $service->rows($actor, $filters, 'custom', $period['from'], $period['to']);
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }
        // Never restrict the candidates before legacy ranking and search.
        $visible = $rows
            ->filter(fn ($row) => $filters->departmentId <= 0 || (int) ($row->department_id ?? 0) === $filters->departmentId)
            ->filter(function ($row) use ($filters): bool {
                if ($filters->search === '') return true;
                return str_contains(mb_strtolower(implode(' ', array_filter([
                    $row->name, $row->department?->name,
                ]))), $filters->search);
            });
        return view('livewire.dashboard.team-panel', [
            'teamReportingPeriod' => $period,
            'teamUserTotal' => $visible->count(),
            'teamHiddenCount' => max(0, $visible->count() - 4),
            'assigneePerformance' => $visible->take(4)->values(),
        ]);
    }
}
