<?php

namespace App\Actions\Orders;

use App\Models\User;
use App\Queries\Orders\OrderListQuery;
use App\Services\AccessControlService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Support\Collection;

final class DeleteOrders
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly OrderListQuery $orders,
        private readonly OrderLifecycleService $jobs,
    ) {}

    public function handle(User $actor, Collection $ids): int
    {
        abort_unless($this->access->can($actor, 'jobs', 'delete'), 403);
        $orders = $this->orders->visibleOrders($actor, $ids);
        foreach ($orders as $order) {
            $this->jobs->delete($order, $actor);
        }
        return $orders->count();
    }
}
