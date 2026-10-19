<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderCoordinator
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, ?int $coordinatorId): FlowJob
    {
        if ($coordinatorId) User::where('is_active', true)->findOrFail($coordinatorId);
        return $this->jobs->updateCoordinator($this->orders->detail($actor, $orderId), $coordinatorId, $actor);
    }
}
