<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\FlowTransitionEvent;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;

class TransitionAuditLogger
{
    public function log(
        FlowJob $job,
        string $type,
        ?User $actor,
        string $source,
        ?string $from = null,
        ?string $to = null,
        ?string $reason = null,
        array $metadata = [],
        ?FlowTask $task = null,
        ?WorkflowPhase $phase = null,
    ): FlowTransitionEvent {
        return FlowTransitionEvent::create([
            'workspace_id' => $job->workspace_id,
            'flow_job_id' => $job->id,
            'flow_task_id' => $task?->id,
            'workflow_phase_id' => $phase?->id,
            'actor_id' => $actor?->id,
            'transition_type' => $type,
            'source' => $source,
            'from_value' => $from,
            'to_value' => $to,
            'reason' => $reason,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }
}
