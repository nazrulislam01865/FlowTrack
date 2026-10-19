<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\User;
use App\Services\Orders\OrderItemService;

final class AddOrderItem
{
    public function __construct(private readonly OrderItemService $service)
    {
    }

    public function handle(FlowJob $order, string $category, string $product, int $quantity, User $actor, float $unitPrice = 0, ?int $catalogProductId = null, ?int $supplierId = null): FlowJobItem
    {
        return $this->service->addItem($order, $category, $product, $quantity, $actor, $unitPrice, $catalogProductId, $supplierId);
    }
}
