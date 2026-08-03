<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkflowEngine
{
    public function __construct(
        private readonly TaskPackActivator $taskPackActivator,
        private readonly ActivityLogger $activityLogger,
        private readonly IdentifierGenerator $identifiers,
        private readonly PhaseGateValidator $phaseGates,
        private readonly ProgressCalculator $progressCalculator,
        private readonly JobHealthSynchronizer $health,
        private readonly PhaseReadinessEvaluator $readiness,
        private readonly TransitionAuditLogger $transitions,
        private readonly FlowNotificationService $notifications,
    ) {}

    public function initialize(FlowJob $job, WorkflowPhase $startPhase, User $actor, ?string $reason = null): void
    {
        if (! $startPhase->allow_job_start) {
            throw new DomainException('The selected workflow phase cannot be used as a starting phase.');
        }

        DB::transaction(function () use ($job, $startPhase, $actor, $reason) {
            $phases = $job->workflow->phases()->where('is_active', true)->orderBy('sequence')->get();

            foreach ($phases as $phase) {
                $status = $phase->sequence < $startPhase->sequence ? 'skipped' : ($phase->id === $startPhase->id ? 'current' : 'pending');
                FlowJobPhaseHistory::create([
                    'flow_job_id' => $job->id,
                    'workflow_phase_id' => $phase->id,
                    'changed_by' => $actor->id,
                    'status' => $status,
                    'readiness_status' => $status === 'current' ? 'in_progress' : ($status === 'skipped' ? 'complete' : 'pending'),
                    'notes' => $status === 'skipped' ? $reason : null,
                    'entered_at' => $status === 'current' ? now() : null,
                    'completed_at' => $status === 'skipped' ? now() : null,
                    'readiness_checked_at' => $status === 'current' ? now() : null,
                ]);
            }

            $job->update([
                'current_phase_id' => $startPhase->id,
                'started_phase_id' => $startPhase->id,
                'start_handling' => $startPhase->sequence > 1 ? 'mid_workflow' : 'normal',
                'start_reason' => $reason,
                'progress' => 0,
            ]);

            // Materialize every non-skipped phase Task Pack when the Job is created.
            // This keeps the complete Job workflow available immediately and prevents
            // the next phase from having to generate/fetch its Tasks after a transition.
            // Phases before a mid-workflow starting point remain intentionally skipped
            // and therefore do not receive active Tasks.
            $created = 0;
            $createdByPhase = [];
            foreach ($phases as $workflowPhase) {
                if ((int) $workflowPhase->sequence < (int) $startPhase->sequence) {
                    continue;
                }

                $phaseCreated = $this->taskPackActivator->activate($job, $workflowPhase, $actor, 'job_creation');
                $created += $phaseCreated;
                $createdByPhase[(int) $workflowPhase->id] = $phaseCreated;
            }

            $this->progressCalculator->recalculateJob($job);
            $this->health->synchronize($job, $actor, 'job_creation');
            $this->readiness->synchronize($job, $startPhase, $actor, 'job_creation');
            $this->activityLogger->log($job->workspace_id, $actor, $job, 'job.created', "Job created at {$startPhase->name}", [
                'tasks_created' => $created,
                'tasks_created_by_phase' => $createdByPhase,
            ]);
            $this->transitions->log($job, 'job.initialized', $actor, 'job_creation', null, $startPhase->name, $reason, [
                'tasks_created' => $created,
                'tasks_created_by_phase' => $createdByPhase,
            ], phase: $startPhase);

            DB::afterCommit(fn () => $this->notifications->jobCreated($job->id, $actor->id));
        });
    }

    public function move(
        FlowJob $job,
        WorkflowPhase $targetPhase,
        User $actor,
        ?string $reason = null,
        string $source = 'job_phase_board',
    ): void {
        $job->loadMissing('workflow.phases', 'currentPhase');

        if ($targetPhase->workflow_template_id !== $job->workflow_template_id) {
            throw new DomainException('The target phase does not belong to this job workflow.');
        }

        if (! $targetPhase->is_active) {
            throw new DomainException('The target workflow phase is disabled.');
        }

        if ($job->excludedPhases()->where('workflow_phase_id', $targetPhase->id)->exists()) {
            throw new DomainException('The selected workflow phase was removed from this Job.');
        }

        $current = $job->currentPhase;
        if (! $current) {
            throw new DomainException('The job does not have an active phase.');
        }

        if ($targetPhase->id === $current->id) {
            return;
        }

        if ($targetPhase->sequence > $current->sequence + 1) {
            $excludedPhaseIds = $job->excludedPhases()->pluck('workflow_phase_id')->map(fn ($id) => (int) $id);
            $skipped = $job->workflow->phases
                ->where('sequence', '>', $current->sequence)
                ->where('sequence', '<', $targetPhase->sequence)
                ->reject(fn (WorkflowPhase $phase) => $excludedPhaseIds->contains((int) $phase->id));

            if ($skipped->isNotEmpty()) {
                $nonSkippable = $skipped->first(fn (WorkflowPhase $phase) => ! $phase->is_skippable);
                if ($nonSkippable) {
                    throw new DomainException("{$nonSkippable->name} cannot be skipped.");
                }

                if (! $reason) {
                    throw new DomainException('A reason is required when phases are skipped.');
                }
            }
        }

        if ($targetPhase->sequence > $current->sequence) {
            $this->phaseGates->assertWorkComplete($job, $current);
        }

        DB::transaction(function () use ($job, $targetPhase, $current, $actor, $reason, $source) {
            FlowJobPhaseHistory::where('flow_job_id', $job->id)
                ->where('workflow_phase_id', $current->id)
                ->update([
                    'status' => 'completed',
                    'readiness_status' => 'complete',
                    'completed_at' => now(),
                    'readiness_checked_at' => now(),
                    'changed_by' => $actor->id,
                ]);

            if ($targetPhase->sequence > $current->sequence + 1) {
                $excludedPhaseIds = $job->excludedPhases()->pluck('workflow_phase_id')->map(fn ($id) => (int) $id);
                $job->workflow->phases()
                    ->whereBetween('sequence', [$current->sequence + 1, $targetPhase->sequence - 1])
                    ->whereNotIn('id', $excludedPhaseIds)
                    ->get()
                    ->each(function (WorkflowPhase $phase) use ($job, $actor, $reason) {
                        FlowJobPhaseHistory::updateOrCreate(
                            ['flow_job_id' => $job->id, 'workflow_phase_id' => $phase->id],
                            [
                                'status' => 'skipped',
                                'readiness_status' => 'complete',
                                'notes' => $reason,
                                'completed_at' => now(),
                                'readiness_checked_at' => now(),
                                'changed_by' => $actor->id,
                            ],
                        );
                    });
            }

            FlowJobPhaseHistory::updateOrCreate(
                ['flow_job_id' => $job->id, 'workflow_phase_id' => $targetPhase->id],
                [
                    'status' => 'current',
                    'readiness_status' => 'in_progress',
                    'entered_at' => now(),
                    'completed_at' => null,
                    'ready_at' => null,
                    'readiness_checked_at' => now(),
                    'readiness_details' => null,
                    'changed_by' => $actor->id,
                    'notes' => $reason,
                ],
            );

            $job->update([
                'current_phase_id' => $targetPhase->id,
                'order_code' => $job->order_code ?: ($targetPhase->short_name === 'Confirmed' ? $this->identifiers->order() : null),
                'progress' => $targetPhase->short_name === 'Completed' ? 100 : $job->progress,
                'status' => $targetPhase->short_name === 'Completed' ? 'completed' : 'in_progress',
                'next_action' => $targetPhase->entry_condition,
                'milestone' => $targetPhase->exit_condition,
            ]);

            // Phase Tasks were materialized during Job creation. Do not generate
            // the target phase again during a transition; simply move the Job onto
            // the already-created Task set.
            $created = 0;
            $job->refresh();
            $this->progressCalculator->recalculateJob($job);
            $this->health->synchronize($job, $actor, $source);
            $this->readiness->synchronize($job, $targetPhase, $actor, $source);
            $this->activityLogger->log(
                $job->workspace_id,
                $actor,
                $job,
                'job.phase_changed',
                "Job moved from {$current->name} to {$targetPhase->name}",
                ['from_phase_id' => $current->id, 'to_phase_id' => $targetPhase->id, 'tasks_created' => $created, 'reason' => $reason, 'source' => $source],
            );
            $this->transitions->log(
                $job,
                'job.phase',
                $actor,
                $source,
                $current->name,
                $targetPhase->name,
                $reason,
                ['from_phase_id' => $current->id, 'to_phase_id' => $targetPhase->id, 'tasks_created' => $created],
                phase: $targetPhase,
            );

            DB::afterCommit(fn () => $this->notifications->jobPhaseChanged(
                $job->id,
                $actor->id,
                $current->name,
                $targetPhase->name,
            ));
        });
    }
}
