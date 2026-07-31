<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Support\Facades\DB;

class WorkflowAutomationService
{
    public function __construct(
        private readonly JobHealthSynchronizer $health,
        private readonly PhaseReadinessEvaluator $readiness,
        private readonly TaskPackActivator $taskPackActivator,
        private readonly WorkflowEngine $workflow,
    ) {}

    /**
     * Synchronize all derived workflow state and optionally auto-advance phases
     * that explicitly enable the rule in Workflow Setup.
     *
     * A row lock prevents two simultaneous Task Board updates from moving the
     * same Job twice when the final required tasks finish at the same time.
     */
    public function synchronize(FlowJob $job, User $actor, string $source = 'system', bool $allowAutoAdvance = true): FlowJob
    {
        $safety = 0;

        do {
            $result = DB::transaction(function () use ($job, $actor, $source, $allowAutoAdvance): array {
                $locked = FlowJob::query()
                    ->whereKey($job->id)
                    ->lockForUpdate()
                    ->with(['currentPhase', 'workflow.phases'])
                    ->firstOrFail();

                if (! $locked->currentPhase) {
                    return ['job' => $locked, 'moved' => false];
                }

                // Self-heal Jobs whose current Task Pack was assigned, edited or
                // partially deleted after the Job entered this Phase.
                $this->taskPackActivator->activate($locked, $locked->currentPhase, $actor, $source);
                $locked->unsetRelation('tasks');

                $this->health->synchronize($locked, $actor, $source);
                $readiness = $this->readiness->synchronize($locked, $locked->currentPhase, $actor, $source);

                if (! $allowAutoAdvance || ! $readiness['ready'] || ! $locked->currentPhase->auto_advance_on_ready) {
                    return ['job' => $locked, 'moved' => false];
                }

                $excludedPhaseIds = $locked->excludedPhases()->pluck('workflow_phase_id')->map(fn ($id) => (int) $id);
                $next = $locked->workflow->phases
                    ->where('is_active', true)
                    ->where('sequence', '>', $locked->currentPhase->sequence)
                    ->reject(fn ($phase) => $excludedPhaseIds->contains((int) $phase->id))
                    ->sortBy('sequence')
                    ->first();

                if (! $next) {
                    return ['job' => $locked, 'moved' => false];
                }

                $this->workflow->move(
                    $locked,
                    $next,
                    $actor,
                    'Automatically advanced because every configured phase gate was satisfied.',
                    'workflow_automation',
                );

                return ['job' => $locked, 'moved' => true];
            });

            if (! $result['moved']) {
                return $result['job']->fresh(['currentPhase', 'workflow.phases']);
            }

            $safety++;
        } while ($safety < 25);

        return $job->fresh(['currentPhase', 'workflow.phases']);
    }
}
