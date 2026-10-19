<?php

namespace App\Actions\Setup;

use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Services\OrderWorkflowSetupService;
use App\Services\WorkflowService;

class SaveOrderWorkflowPhaseAction
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly OrderWorkflowSetupService $orders,
    ) {}

    public function execute(WorkflowTemplate $workflow, WorkflowPhase $phase, array $data): int
    {
        $sequence = (int) $phase->sequence;
        $this->orders->assertCompatibleTaskPack((int) $data['task_pack_id'], $sequence);
        $this->workflows->savePhase($workflow, $data, $phase);

        return $this->orders->publishWorkflow((int) $workflow->id);
    }
}
