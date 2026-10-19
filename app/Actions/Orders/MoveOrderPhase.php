<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;

final class MoveOrderPhase
{
    public function __construct(private readonly OrderWorkflowService $service)
    {
    }

    public function handle(FlowJob $order, int $phaseId, User $actor): FlowJob
    {
        return $this->service->moveToPhase($order, $phaseId, $actor);
    }
}
