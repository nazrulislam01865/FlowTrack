<?php

namespace App\Queries\Dashboard;

use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;

final class DashboardPrimaryQuery
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly DashboardSummaryQuery $summary,
        private readonly DashboardDistributionQuery $distribution,
        private readonly DashboardAttentionQuery $attention,
        private readonly DashboardClientPortfolioQuery $portfolio,
        private readonly DashboardTeamPerformanceQuery $team,
        private readonly DashboardPriorityWorkQuery $priority,
        private readonly DashboardActivityQuery $activity,
        private readonly DashboardCatalogueReadinessQuery $catalogue,
        private readonly DashboardReferenceQuery $references,
    ) {}

    public function handle(User $actor, DashboardFilterData $filters): array
    {
        abort_unless($this->access->can($actor, 'dashboard', 'view'), 403);
        $period = $this->team->dashboardPeriod($actor, $filters->rangeDays);

        return array_merge(
            ['metrics' => $this->summary->handle($actor, $filters)],
            $this->distribution->handle($actor, $filters),
            $this->attention->handle($actor, $filters),
            ['clientPortfolio' => $this->portfolio->handle($actor, $filters)],
            ['assigneePerformance' => $this->access->can($actor, 'reports', 'view')
                ? $this->team->rows($actor, $filters, 'custom', $period['from'], $period['to'])
                : collect()],
            ['teamReportingPeriod' => $period],
            $this->priority->handle($actor, $filters),
            ['recentActivity' => $this->activity->handle($actor, $filters)],
            ['catalogueReadiness' => $this->catalogue->handle($actor)],
            $this->references->handle($actor),
        );
    }
}
