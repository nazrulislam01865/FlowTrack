<?php

namespace App\Services\Dashboard;

use App\Services\LegacyDashboardService;

final class DashboardReportingService
{
    public function __construct(private readonly LegacyDashboardService $legacy)
    {
    }

    public function dashboardReportingPeriod(mixed ...$arguments): mixed
    {
        return $this->legacy->dashboardReportingPeriod(...$arguments);
    }

    public function teamReportingPeriod(mixed ...$arguments): mixed
    {
        return $this->legacy->teamReportingPeriod(...$arguments);
    }

    public function assigneePerformance(mixed ...$arguments): mixed
    {
        return $this->legacy->assigneePerformance(...$arguments);
    }

    public function decorateTeamPerformance(mixed ...$arguments): mixed
    {
        return $this->legacy->decorateTeamPerformance(...$arguments);
    }

    public function sortTeamPerformance(mixed ...$arguments): mixed
    {
        return $this->legacy->sortTeamPerformance(...$arguments);
    }

}
