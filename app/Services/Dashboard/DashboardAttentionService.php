<?php

namespace App\Services\Dashboard;

use App\Services\LegacyDashboardService;

final class DashboardAttentionService
{
    public function __construct(private readonly LegacyDashboardService $legacy)
    {
    }

    public function attentionJobs(mixed ...$arguments): mixed
    {
        return $this->legacy->attentionJobs(...$arguments);
    }

    public function attentionOrders(mixed ...$arguments): mixed
    {
        return $this->legacy->attentionOrders(...$arguments);
    }

    public function attentionInquiries(mixed ...$arguments): mixed
    {
        return $this->legacy->attentionInquiries(...$arguments);
    }

    public function attentionTasks(mixed ...$arguments): mixed
    {
        return $this->legacy->attentionTasks(...$arguments);
    }

}
