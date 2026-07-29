<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;

class DocumentWorkflowLinker
{
    public function __construct(
        private readonly WorkflowAutomationService $automation,
        private readonly PhaseReadinessEvaluator $readiness,
        private readonly WorkflowEngine $workflow,
    ) {}

    /**
     * Recalculate the linked Job after a document upload. A user with the
     * Documents → Link permission may explicitly continue the Job when every
     * current-phase gate is satisfied. All movement still goes through the
     * shared Workflow Engine and audit trail.
     *
     * @return array{moved:bool, ready:bool, blockers:array<int,string>, from_phase_id:?int, to_phase_id:?int}
     */
    public function synchronize(FlowJob $job, User $actor, bool $advanceWhenReady): array
    {
        $beforePhaseId = $job->current_phase_id;

        if (! $advanceWhenReady) {
            $updated = $this->automation->synchronize($job, $actor, 'document_upload');

            return [
                'moved' => (int) $updated->current_phase_id !== (int) $beforePhaseId,
                'ready' => false,
                'blockers' => [],
                'from_phase_id' => $beforePhaseId,
                'to_phase_id' => $updated->current_phase_id,
            ];
        }

        $updated = $this->automation->synchronize($job, $actor, 'document_link', false);
        $updated->loadMissing(['currentPhase', 'workflow.phases']);
        if (! $updated->currentPhase) {
            return ['moved' => false, 'ready' => false, 'blockers' => ['The Job does not have a current phase.'], 'from_phase_id' => $beforePhaseId, 'to_phase_id' => null];
        }

        $result = $this->readiness->synchronize($updated, $updated->currentPhase, $actor, 'document_link');
        if (! $result['ready']) {
            return [
                'moved' => false,
                'ready' => false,
                'blockers' => $result['blockers'] ?? [],
                'from_phase_id' => $beforePhaseId,
                'to_phase_id' => $updated->current_phase_id,
            ];
        }

        $next = $updated->workflow->phases
            ->where('is_active', true)
            ->where('sequence', '>', $updated->currentPhase->sequence)
            ->sortBy('sequence')
            ->first();

        if (! $next) {
            return ['moved' => false, 'ready' => true, 'blockers' => [], 'from_phase_id' => $beforePhaseId, 'to_phase_id' => $updated->current_phase_id];
        }

        $this->workflow->move(
            $updated,
            $next,
            $actor,
            'Continued from the Documents page after the required document was linked and every phase gate was satisfied.',
            'document_linker',
        );

        return [
            'moved' => true,
            'ready' => true,
            'blockers' => [],
            'from_phase_id' => $beforePhaseId,
            'to_phase_id' => $next->id,
        ];
    }
}
