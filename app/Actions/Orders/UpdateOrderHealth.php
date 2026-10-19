<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderHealth
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, string $health): FlowJob
    {
        $health = trim($health);
        abort_if($health === '', 422, 'Health is required.');
        return $this->jobs->updateHealth($this->orders->detail($actor, $orderId), $health, $actor);
    }
}
