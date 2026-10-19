<?php

namespace App\Actions\Orders;

use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\Orders\OrderHoldService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\TaskService;

/** Delete one visible Order task while preserving the existing audit/progress side effects. */
final class DeleteOrderTask
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly TaskService $tasks,
        private readonly VisibleOrderQuery $orders,
        private readonly OrderWorkflowService $jobs,
    ) {
    }

    public function handle(User $actor, int $orderId, int $taskId): void
    {
        abort_unless($this->access->can($actor, 'tasks', 'delete'), 403);

        $task = $this->tasks->visibleQuery($actor)
            ->where('flow_job_id', $orderId)
            ->findOrFail($taskId);
        $job = $this->orders->detail($actor, $orderId);
        app(OrderHoldService::class)->assertNotHeld($job);
        $title = (string) $task->title;
        $taskNumber = (string) ($task->task_number ?? '');

        $task->delete();
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.task_deleted',
            'description' => 'Task deleted: '.$title,
            'meta' => ['task_id' => $taskId, 'task_number' => $taskNumber, 'title' => $title],
        ]);
        $this->jobs->recalculateProgress($job->refresh());
    }
}
