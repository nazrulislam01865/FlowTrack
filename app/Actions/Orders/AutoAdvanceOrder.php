<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;

final class AutoAdvanceOrder
{
    public function __construct(private readonly OrderWorkflowService $service)
    {
    }

    public function handle(FlowJob $order, User $actor): FlowJob
    {
        return $this->service->maybeAutoAdvance($order, $actor);
    }
}
