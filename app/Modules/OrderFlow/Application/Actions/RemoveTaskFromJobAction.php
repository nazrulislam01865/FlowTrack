<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RemoveTaskFromJobAction
{
    public function __construct(
        private readonly ActivityLogger $activities,
        private readonly ProgressCalculator $progress,
        private readonly WorkflowAutomationService $automation,
        private readonly FlowNotificationService $notifications,
    ) {}

    public function execute(FlowTask $task, User $actor, string $source = 'task_deletion'): FlowJob
    {
        $task->loadMissing('job', 'phase');
        $job = $task->job;

        DB::transaction(function () use ($task, $job, $actor, $source): void {
            $taskIds = $this->taskAndDescendantIds($task);

            FlowTask::query()
                ->where('flow_job_id', $job->id)
                ->whereNotIn('id', $taskIds)
                ->whereIn('dependency_id', $taskIds)
                ->update(['dependency_id' => null]);

            FlowTask::query()
                ->where('flow_job_id', $job->id)
                ->whereNotIn('id', $taskIds)
                ->whereIn('parent_id', $taskIds)
                ->update(['parent_id' => null]);

            FlowTask::query()
                ->whereIn('id', $taskIds)
                ->orderByDesc('id')
                ->get()
                ->each(function (FlowTask $removedTask) use ($actor): void {
                    $removedTask->forceFill([
                        'removed_from_job_at' => now(),
                        'removed_from_job_by' => $actor->id,
                    ])->save();
                    $removedTask->delete();
                });

            $this->activities->log(
                (int) $job->workspace_id,
                $actor,
                $job,
                'job.task_removed',
                "Task {$task->code} removed from {$job->code}",
                [
                    'task_id' => $task->id,
                    'removed_task_ids' => $taskIds->values()->all(),
                    'workflow_phase_id' => $task->workflow_phase_id,
                    'source' => $source,
                ],
            );

            $this->progress->recalculateJob($job);
            $this->automation->synchronize($job, $actor, $source, false);

            DB::afterCommit(fn () => $this->notifications->taskRemoved($job->id, [
                'id' => (int) $task->id,
                'code' => (string) $task->code,
                'title' => (string) $task->title,
                'phase' => $task->phase?->name,
                'assignee_id' => $task->assignee_id ? (int) $task->assignee_id : null,
                'descendant_count' => max(0, $taskIds->count() - 1),
            ], $actor->id));
        });

        return $job->fresh(['currentPhase', 'workflow.phases']);
    }

    /** @return Collection<int, int> */
    private function taskAndDescendantIds(FlowTask $task): Collection
    {
        $ids = collect([(int) $task->id]);
        $frontier = $ids;

        while ($frontier->isNotEmpty()) {
            $children = FlowTask::query()
                ->where('flow_job_id', $task->flow_job_id)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($ids)
                ->values();

            if ($children->isEmpty()) {
                break;
            }

            $ids = $ids->merge($children)->unique()->values();
            $frontier = $children;
        }

        return $ids;
    }
}
