<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;

final class AppendOrderTask
{
    public function __construct(private readonly OrderWorkflowService $service)
    {
    }

    public function handle(FlowJob $order, array $data, User $actor): Task
    {
        return $this->service->appendTask($order, $data, $actor);
    }
}
