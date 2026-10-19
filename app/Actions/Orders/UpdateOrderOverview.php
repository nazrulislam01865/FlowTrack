<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderOverview
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, string $title, string $description): FlowJob
    {
        return $this->jobs->updateOverviewDetails($this->orders->detail($actor, $orderId), $title, $description, $actor);
    }
}
