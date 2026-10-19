<?php

namespace App\Queries\Dashboard;

use App\DTOs\Dashboard\DashboardFilterData;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\Dashboard\DashboardOverviewService;

final class DashboardSecondaryQuery
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly DashboardTeamPerformanceQuery $team,
        private readonly DashboardAttentionQuery $attention,
        private readonly DashboardClientPortfolioQuery $portfolio,
        private readonly DashboardOverviewService $overview,
    ) {}

    public function handle(User $actor): array
    {
        abort_unless($this->access->can($actor, 'dashboard', 'view'), 403);
        $filters = new DashboardFilterData();

        return [
            'assigneePerformance' => $this->access->can($actor, 'reports', 'view') ? $this->team->rows($actor, $filters) : collect(),
            'attentionTasks' => $this->attention->handle($actor, $filters)['attentionTasks'],
            'ongoingJobs' => $this->overview->ongoingJobs($actor),
            'ongoingTasks' => $this->overview->ongoingTasks($actor),
            'recentActivity' => $this->overview->recentActivity($actor),
            'clientPortfolio' => $this->portfolio->handle($actor, $filters),
        ];
    }
}
