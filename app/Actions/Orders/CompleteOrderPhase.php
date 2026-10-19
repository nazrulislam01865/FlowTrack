<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;

final class CompleteOrderPhase
{
    public function __construct(private readonly OrderWorkflowService $service)
    {
    }

    public function handle(FlowJob $order, User $actor, bool $automatic = false): FlowJob
    {
        return $this->service->completePhase($order, $actor, $automatic);
    }
}
