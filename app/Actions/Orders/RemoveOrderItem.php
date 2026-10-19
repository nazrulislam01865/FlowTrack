<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\User;
use App\Services\Orders\OrderItemService;

final class RemoveOrderItem
{
    public function __construct(private readonly OrderItemService $service)
    {
    }

    public function handle(FlowJob $order, FlowJobItem $item, User $actor, ?string $reason = null): void
    {
        $this->service->removeItem($order, $item, $actor, $reason);
    }
}
