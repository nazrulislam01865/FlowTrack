<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderPriority
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, string $priority): FlowJob
    {
        $priority = trim($priority);
        abort_if($priority === '', 422, 'Priority is required.');
        return $this->jobs->updatePriority($this->orders->detail($actor, $orderId), $priority, $actor);
    }
}
