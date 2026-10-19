<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderShippingDetails
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, array $changes): FlowJob
    {
        return $this->jobs->updateShippingDetails($this->orders->detail($actor, $orderId), $changes, $actor);
    }
}
