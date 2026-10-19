<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\OrderListQuery;
use App\Services\Orders\OrderLifecycleService;

final class DeleteOrder
{
    public function __construct(
        private readonly OrderListQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId): FlowJob
    {
        $order = $this->orders->visible($actor, $orderId);
        $this->jobs->delete($order, $actor);
        return $order;
    }
}
