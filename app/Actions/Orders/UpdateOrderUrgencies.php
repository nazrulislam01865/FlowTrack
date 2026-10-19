<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderUrgencies
{
    public function __construct(private readonly OrderLifecycleService $service)
    {
    }

    public function handle(FlowJob $order, string $field, array $urgencyIds, User $actor): FlowJob
    {
        return $this->service->updateUrgencies($order, $field, $urgencyIds, $actor);
    }
}
