<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\User;
use App\Services\Orders\OrderItemService;

final class UpdateOrderItemDetails
{
    public function __construct(private readonly OrderItemService $service)
    {
    }

    public function handle(FlowJob $order, FlowJobItem $item, array $data, User $actor): FlowJobItem
    {
        return $this->service->updateItemDetails($order, $item, $data, $actor);
    }
}
