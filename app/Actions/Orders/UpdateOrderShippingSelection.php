<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderShippingSelection
{
    public function __construct(private readonly OrderLifecycleService $service)
    {
    }

    public function handle(FlowJob $order, int $methodId, ?int $urgencyId, User $actor): FlowJob
    {
        return $this->service->updateShippingSelection($order, $methodId, $urgencyId, $actor);
    }
}
