<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\PhaseGateValidator;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowPhaseApproval;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use DomainException;

class ApproveJobPhaseAction
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly PhaseGateValidator $phaseGates,
    ) {}

    public function execute(FlowJob $job, WorkflowPhase $phase, User $actor, ?string $notes = null): FlowPhaseApproval
    {
        if ($phase->workflow_template_id !== $job->workflow_template_id) {
            throw new DomainException('The phase does not belong to this Job workflow.');
        }

        if ($job->current_phase_id !== $phase->id) {
            throw new DomainException('Only the current Job phase can be approved.');
        }

        if (! $phase->requires_approval) {
            throw new DomainException('This workflow phase does not require approval.');
        }

        $this->phaseGates->assertWorkComplete($job, $phase);

        $approval = FlowPhaseApproval::updateOrCreate(
            ['flow_job_id' => $job->id, 'workflow_phase_id' => $phase->id],
            ['approved_by' => $actor->id, 'status' => 'approved', 'notes' => $notes, 'approved_at' => now()],
        );

        $this->activityLogger->log(
            $job->workspace_id,
            $actor,
            $job,
            'job.phase_approved',
            "{$phase->name} approved for {$job->code}",
            ['workflow_phase_id' => $phase->id, 'notes' => $notes],
        );

        return $approval->fresh(['approvedBy', 'phase']);
    }
}
