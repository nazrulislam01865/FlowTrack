<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class SetOrderAttention
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, string $reason): FlowJob
    {
        return $this->jobs->setOrderAttentionReason($this->orders->base($actor, $orderId), $reason, $actor);
    }
}
