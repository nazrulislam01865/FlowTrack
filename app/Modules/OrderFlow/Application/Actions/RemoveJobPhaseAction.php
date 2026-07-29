<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\TransitionAuditLogger;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseExclusion;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use DomainException;
use Illuminate\Support\Facades\DB;

class RemoveJobPhaseAction
{
    public function __construct(
        private readonly ActivityLogger $activities,
        private readonly TransitionAuditLogger $transitions,
        private readonly WorkflowAutomationService $automation,
        private readonly FlowNotificationService $notifications,
    ) {}

    public function execute(FlowJob $job, WorkflowPhase $phase, User $actor, ?string $reason = null): FlowJob
    {
        $job->loadMissing('workflow.phases', 'currentPhase', 'excludedPhases');

        if ((int) $phase->workflow_template_id !== (int) $job->workflow_template_id) {
            throw new DomainException('The selected phase does not belong to this Job workflow.');
        }

        if ($job->excludedPhases->contains('workflow_phase_id', $phase->id)) {
            return $job;
        }

        $excludedIds = $job->excludedPhases->pluck('workflow_phase_id')->map(fn ($id) => (int) $id)->push((int) $phase->id);
        $remaining = $job->workflow->phases
            ->where('is_active', true)
            ->reject(fn (WorkflowPhase $candidate) => $excludedIds->contains((int) $candidate->id))
            ->sortBy('sequence')
            ->values();

        if ($remaining->isEmpty()) {
            throw new DomainException('A Job must retain at least one active workflow phase.');
        }

        $current = $job->currentPhase;
        $replacement = null;
        if ($current && (int) $current->id === (int) $phase->id) {
            $replacement = $remaining->first(fn (WorkflowPhase $candidate) => (int) $candidate->sequence > (int) $phase->sequence)
                ?? $remaining->reverse()->first(fn (WorkflowPhase $candidate) => (int) $candidate->sequence < (int) $phase->sequence);
        }

        DB::transaction(function () use ($job, $phase, $actor, $reason, $current, $replacement): void {
            $locked = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            $phaseTaskIds = $locked->tasks()
                ->where('workflow_phase_id', $phase->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            if ($phaseTaskIds->isNotEmpty()) {
                $locked->tasks()
                    ->whereNotIn('id', $phaseTaskIds)
                    ->whereIn('dependency_id', $phaseTaskIds)
                    ->update(['dependency_id' => null]);
                $locked->tasks()
                    ->whereNotIn('id', $phaseTaskIds)
                    ->whereIn('parent_id', $phaseTaskIds)
                    ->update(['parent_id' => null]);

                FlowTask::query()
                    ->whereIn('id', $phaseTaskIds)
                    ->get()
                    ->each(function (FlowTask $task) use ($actor): void {
                        $task->forceFill([
                            'removed_from_job_at' => now(),
                            'removed_from_job_by' => $actor->id,
                        ])->save();
                        $task->delete();
                    });
            }

            $locked->documents()
                ->where('workflow_phase_id', $phase->id)
                ->update([
                    'workflow_phase_id' => null,
                    'task_pack_item_id' => null,
                    'linked_by' => null,
                    'linked_at' => null,
                ]);

            FlowJobPhaseExclusion::updateOrCreate(
                ['flow_job_id' => $locked->id, 'workflow_phase_id' => $phase->id],
                ['removed_by' => $actor->id, 'reason' => blank($reason) ? null : trim((string) $reason)],
            );

            FlowJobPhaseHistory::query()
                ->where('flow_job_id', $locked->id)
                ->where('workflow_phase_id', $phase->id)
                ->delete();

            if ($current && (int) $current->id === (int) $phase->id && $replacement) {
                FlowJobPhaseHistory::query()
                    ->where('flow_job_id', $locked->id)
                    ->where('status', 'current')
                    ->update([
                        'status' => 'completed',
                        'readiness_status' => 'complete',
                        'completed_at' => now(),
                        'changed_by' => $actor->id,
                    ]);

                FlowJobPhaseHistory::updateOrCreate(
                    ['flow_job_id' => $locked->id, 'workflow_phase_id' => $replacement->id],
                    [
                        'status' => 'current',
                        'readiness_status' => 'in_progress',
                        'entered_at' => now(),
                        'completed_at' => null,
                        'ready_at' => null,
                        'readiness_checked_at' => now(),
                        'readiness_details' => null,
                        'changed_by' => $actor->id,
                        'notes' => blank($reason) ? 'Previous phase removed from this Job.' : trim((string) $reason),
                    ],
                );

                $isCompleted = strtoupper((string) $replacement->short_name) === 'COMPLETED';
                $locked->update([
                    'current_phase_id' => $replacement->id,
                    'started_phase_id' => (int) $locked->started_phase_id === (int) $phase->id ? $replacement->id : $locked->started_phase_id,
                    'status' => $isCompleted ? 'completed' : 'in_progress',
                    'progress' => $isCompleted ? 100 : min((int) $locked->progress, 99),
                    'next_action' => $replacement->entry_condition,
                    'milestone' => $replacement->exit_condition,
                ]);
            }

            $this->activities->log(
                (int) $locked->workspace_id,
                $actor,
                $locked,
                'job.phase_removed',
                "Phase {$phase->name} removed from {$locked->code}",
                [
                    'workflow_phase_id' => $phase->id,
                    'replacement_phase_id' => $replacement?->id,
                    'reason' => blank($reason) ? null : trim((string) $reason),
                ],
            );

            $this->transitions->log(
                $locked,
                'job.phase_removed',
                $actor,
                'job_phase_removal',
                $phase->name,
                $replacement?->name,
                blank($reason) ? null : trim((string) $reason),
                ['removed_phase_id' => $phase->id, 'replacement_phase_id' => $replacement?->id],
                phase: $replacement,
            );
        });

        $updated = $job->fresh(['currentPhase', 'workflow.phases', 'excludedPhases']);
        $this->automation->synchronize($updated, $actor, 'job_phase_removal', false);
        $this->notifications->jobPhaseRemoved(
            $updated->id,
            $actor->id,
            $phase->name,
            $updated->currentPhase?->name,
            $reason,
        );

        return $updated->fresh(['currentPhase', 'workflow.phases', 'excludedPhases']);
    }
}
