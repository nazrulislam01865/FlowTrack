<?php

namespace App\Actions\Orders;

use App\Models\OrderHold;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderHoldService;

final class ReleaseOrderHold
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderHoldService $holds,
    ) {}

    public function handle(User $actor, int $orderId): OrderHold
    {
        return $this->holds->release(
            $this->orders->base($actor, $orderId),
            $actor,
        );
    }
}
