<?php

namespace App\Actions\Orders;

use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderOwner
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, ?int $ownerId): ?User
    {
        $owner = $ownerId ? User::where('is_active', true)->findOrFail($ownerId) : null;
        $order = $this->orders->detail($actor, $orderId);
        $this->jobs->updateOwner($order, $ownerId, $actor);
        return $owner;
    }
}
