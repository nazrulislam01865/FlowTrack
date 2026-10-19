<?php

namespace App\Actions\Orders;

use App\Models\OrderHold;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderHoldService;

final class PlaceOrderOnHold
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderHoldService $holds,
    ) {}

    public function handle(User $actor, int $orderId, string $holdFrom, ?string $reason = null): OrderHold
    {
        return $this->holds->place(
            $this->orders->base($actor, $orderId),
            $actor,
            $holdFrom,
            $reason,
        );
    }
}
