<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\WorkflowEngine;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;

class MoveJobPhaseAction
{
    public function __construct(private readonly WorkflowEngine $workflowEngine) {}

    public function execute(FlowJob $job, int $phaseId, User $actor, ?string $reason = null): FlowJob
    {
        $phase = WorkflowPhase::query()->findOrFail($phaseId);
        $this->workflowEngine->move($job, $phase, $actor, $reason);

        return $job->fresh(['currentPhase', 'workflow.phases']);
    }
}
