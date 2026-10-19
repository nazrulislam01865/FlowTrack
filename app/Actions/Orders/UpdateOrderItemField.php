<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\User;
use App\Services\Orders\OrderItemService;

final class UpdateOrderItemField
{
    public function __construct(private readonly OrderItemService $service)
    {
    }

    public function handle(FlowJob $order, FlowJobItem $item, string $field, mixed $value, User $actor): FlowJobItem
    {
        return $this->service->updateItem($order, $item, $field, $value, $actor);
    }
}
