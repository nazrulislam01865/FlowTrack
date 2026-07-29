<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Support\Facades\DB;

class UpdateTaskDueDateAction
{
    public function __construct(
        private readonly ActivityLogger $activities,
        private readonly WorkflowAutomationService $automation,
        private readonly FlowNotificationService $notifications,
    ) {}

    public function execute(FlowTask $task, ?string $dueDate, User $actor, string $source = 'task_due_date'): FlowTask
    {
        return DB::transaction(function () use ($task, $dueDate, $actor, $source): FlowTask {
            $task->loadMissing('job');
            $before = $task->due_date?->toDateString();
            $after = blank($dueDate) ? null : $dueDate;

            $task->update(['due_date' => $after]);

            $this->activities->log(
                (int) $task->job->workspace_id,
                $actor,
                $task,
                'task.due_date_changed',
                "Task {$task->code} due date changed",
                ['from' => $before, 'to' => $after, 'source' => $source],
            );

            $this->automation->synchronize($task->job, $actor, $source, false);
            DB::afterCommit(fn () => $this->notifications->taskDueDateChanged($task->id, $actor->id, $before, $after));

            return $task->fresh([
                'job.client', 'phase', 'parent', 'dependency', 'creator', 'assignee',
                'status', 'priority', 'checklistItems', 'comments.user',
            ]);
        });
    }
}
