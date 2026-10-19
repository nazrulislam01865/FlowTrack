<?php

namespace App\Services\Dashboard;

use App\Services\LegacyDashboardService;

final class DashboardOverviewService
{
    public function __construct(private readonly LegacyDashboardService $legacy)
    {
    }

    public function primaryData(mixed ...$arguments): mixed
    {
        return $this->legacy->primaryData(...$arguments);
    }

    public function secondaryData(mixed ...$arguments): mixed
    {
        return $this->legacy->secondaryData(...$arguments);
    }

    public function data(mixed ...$arguments): mixed
    {
        return $this->legacy->data(...$arguments);
    }

    public function metrics(mixed ...$arguments): mixed
    {
        return $this->legacy->metrics(...$arguments);
    }

    public function summary(mixed ...$arguments): mixed
    {
        return $this->legacy->summary(...$arguments);
    }

    public function summaryForFilters(mixed ...$arguments): mixed
    {
        return $this->legacy->summaryForFilters(...$arguments);
    }

    public function priorityJobs(mixed ...$arguments): mixed
    {
        return $this->legacy->priorityJobs(...$arguments);
    }

    public function priorityInquiries(mixed ...$arguments): mixed
    {
        return $this->legacy->priorityInquiries(...$arguments);
    }

    public function priorityTasks(mixed ...$arguments): mixed
    {
        return $this->legacy->priorityTasks(...$arguments);
    }

    public function ongoingJobs(mixed ...$arguments): mixed
    {
        return $this->legacy->ongoingJobs(...$arguments);
    }

    public function ongoingTasks(mixed ...$arguments): mixed
    {
        return $this->legacy->ongoingTasks(...$arguments);
    }

    public function recentActivity(mixed ...$arguments): mixed
    {
        return $this->legacy->recentActivity(...$arguments);
    }

    public function recentOperationalActivity(mixed ...$arguments): mixed
    {
        return $this->legacy->recentOperationalActivity(...$arguments);
    }

    public function clientPortfolio(mixed ...$arguments): mixed
    {
        return $this->legacy->clientPortfolio(...$arguments);
    }

    public function flowDistribution(mixed ...$arguments): mixed
    {
        return $this->legacy->flowDistribution(...$arguments);
    }

    public function taskStatusDistribution(mixed ...$arguments): mixed
    {
        return $this->legacy->taskStatusDistribution(...$arguments);
    }

    public function catalogueReadiness(mixed ...$arguments): mixed
    {
        return $this->legacy->catalogueReadiness(...$arguments);
    }

    public function dashboardClients(mixed ...$arguments): mixed
    {
        return $this->legacy->dashboardClients(...$arguments);
    }

    public function dashboardDepartments(mixed ...$arguments): mixed
    {
        return $this->legacy->dashboardDepartments(...$arguments);
    }

}
