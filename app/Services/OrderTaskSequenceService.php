<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Support\BoardLaneResolver;
use App\Support\OrderTaskRequirement;
use Illuminate\Support\Collection;

/**
 * Authoritative normal-path sequencing for generated Order tasks.
 *
 * Blade/JavaScript never decide which configured task is unlocked. The first
 * incomplete required Task Pack item in the active phase is READY; later
 * required items stay Not Started until their predecessor is completed.
 * Special business branches can layer on top of this service without
 * duplicating dependency rules in Livewire components.
 */
class OrderTaskSequenceService
{
    public function assertStatusActionable(Task $task): void
    {
        if (!$task->task_pack_task_id) return; // Manual tasks remain independently editable.

        $task->loadMissing(['job.phase', 'setupTemplate']);
        $job = $task->job;
        if (!$job) return;

        abort_unless((int) $task->workflow_phase_id === (int) $job->workflow_phase_id, 422, 'This task is locked until its workflow stage is active.');

        if (OrderTaskRequirement::isRegularOptional($task)) {
            abort_unless(
                $this->earlierRequiredTasksComplete($job, $task),
                422,
                'Complete the previous required task before performing this optional task.',
            );

            return;
        }

        $first = $this->firstIncompleteRequiredTask($job, (int) $task->workflow_phase_id);
        if (!$first || (int) $first->id === (int) $task->id) return;
        if (BoardLaneResolver::isCompleted((string) $task->status) || $task->completed_at) return;

        // Artwork sample approval is an explicit conditional branch. The
        // client-decision task intentionally stays WAITING as the phase blocker
        // while the optional Sample Approval task becomes actionable.
        if (strcasecmp(trim((string) $first->status), 'Waiting for Sample Approval') === 0
            && str_contains(strtolower((string) $task->title), 'sample approval')) {
            return;
        }

        if (strcasecmp(trim((string) $first->status), 'Waiting for QC Issue Resolution') === 0
            && str_contains(strtolower((string) $task->title), 'qc issue')) {
            return;
        }

        abort(422, 'Complete the previous required task before changing this task status.');
    }

    public function synchronizeCurrentPhase(FlowJob $job, ?User $actor = null): void
    {
        $job->loadMissing('phase');
        if (!$job->phase) return;
        $this->synchronizePhase($job, $job->phase, $actor);
    }

    public function synchronizePhase(FlowJob $job, WorkflowPhase $phase, ?User $actor = null): void
    {
        if ((int) $job->workflow_phase_id !== (int) $phase->id || $job->status === 'Draft' || $job->completed_at) return;

        $tasks = $this->requiredGeneratedTasks($job, $phase->id);
        if ($tasks->isEmpty()) return;

        $firstOpen = $tasks->first(fn (Task $task) => !$task->completed_at && !BoardLaneResolver::isCompleted((string) $task->status));
        if (!$firstOpen) return;

        $rules = app(OrderTaskFlagService::class);
        $ready = $rules->readyStatus();
        $notStarted = $rules->notStartedStatus();
        $readyId = $rules->statusRecord($ready, false)?->id;
        $notStartedId = $rules->statusRecord($notStarted, false)?->id;

        foreach ($tasks as $task) {
            if ($task->completed_at || BoardLaneResolver::isCompleted((string) $task->status)) continue;

            if ((int) $task->id === (int) $firstOpen->id) {
                if (BoardLaneResolver::isNotStarted((string) $task->status)) {
                    $task->update([
                        'status' => $ready,
                        'order_task_status_id' => $readyId,
                        'start_date' => $task->start_date ?: app(WorkspaceSettingsService::class)->localToday(),
                    ]);
                }
                continue;
            }

            // Only collapse generic READY tasks. Waiting/issue/revision states
            // belong to specialized handlers and must not be overwritten here.
            if (strcasecmp(trim((string) $task->status), trim($ready)) === 0) {
                $task->update([
                    'status' => $notStarted,
                    'order_task_status_id' => $notStartedId,
                    'progress' => 0,
                    'completed_at' => null,
                ]);
            }
        }
    }


    private function earlierRequiredTasksComplete(FlowJob $job, Task $task): bool
    {
        $task->loadMissing('setupTemplate');
        $sequence = OrderTaskRequirement::sequence($task);

        return Task::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $task->workflow_phase_id)
            ->whereNotNull('task_pack_task_id')
            ->with('setupTemplate:id,task_pack_id,is_required,sort_order,automation_key')
            ->get()
            ->filter(fn (Task $candidate) =>
                OrderTaskRequirement::isRequired($candidate)
                && OrderTaskRequirement::sequence($candidate) < $sequence
            )
            ->every(fn (Task $candidate) =>
                (bool) $candidate->completed_at
                || BoardLaneResolver::isCompleted((string) $candidate->status)
                || in_array(strtolower(trim((string) $candidate->status)), ['skipped', 'not applicable', 'n/a'], true)
            );
    }

    public function firstIncompleteRequiredTask(FlowJob $job, int $phaseId): ?Task
    {
        return $this->requiredGeneratedTasks($job, $phaseId)
            ->first(fn (Task $task) => !$task->completed_at && !BoardLaneResolver::isCompleted((string) $task->status));
    }

    /** @return Collection<int, Task> */
    private function requiredGeneratedTasks(FlowJob $job, int $phaseId): Collection
    {
        return Task::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $phaseId)
            ->whereNotNull('task_pack_task_id')
            ->with('setupTemplate:id,task_pack_id,is_required,sort_order')
            ->get()
            ->filter(fn (Task $task) => ($task->setupTemplate?->is_required ?? true) !== false)
            ->sortBy(fn (Task $task) => [(int) ($task->setupTemplate?->sort_order ?? 999999), (int) $task->id])
            ->values();
    }
}
