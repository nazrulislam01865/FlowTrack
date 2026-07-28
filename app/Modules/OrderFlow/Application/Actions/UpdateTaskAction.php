<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateTaskAction
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly ProgressCalculator $progressCalculator,
    ) {}

    public function execute(FlowTask $task, array $data, User $actor): FlowTask
    {
        return DB::transaction(function () use ($task, $data, $actor) {
            $workspaceId = $task->job->workspace_id;
            $status = MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->findOrFail($data['task_status_id']);
            if ($status->type !== \App\Modules\OrderFlow\Business\Enums\MasterDataType::TaskStatus->value) {
                throw new DomainException('The selected status is not a task status for this workspace.');
            }

            if (! empty($data['assignee_id']) && ! WorkspaceMembership::query()->where('workspace_id', $workspaceId)->where('user_id', $data['assignee_id'])->where('status', 'active')->exists()) {
                throw new DomainException('The selected assignee is not an active member of this workspace.');
            }

            $task->loadMissing('dependency');
            if (in_array($status->code, ['IP', 'CMP'], true) && $task->dependency && ! $task->dependency->completed_at) {
                throw new DomainException("Complete dependency {$task->dependency->code} before starting or completing this task.");
            }

            $blockerReason = trim((string) ($data['blocker_reason'] ?? ''));

            if (in_array($status->code, ['BLK', 'ATTN'], true) && $blockerReason === '') {
                throw new DomainException('A blocker or attention reason is required.');
            }

            $isCompleted = $status->code === 'CMP';
            $task->update([
                'task_status_id' => $status->id,
                'assignee_id' => $data['assignee_id'] ?? $task->assignee_id,
                'progress' => $this->progressCalculator->forTaskStatus($status),
                'attention_required' => (bool) ($data['attention_required'] ?? false),
                'blocker_reason' => $blockerReason ?: null,
                'completed_at' => $isCompleted ? now() : null,
            ]);

            $completedChecklistIds = collect($data['completed_checklist_ids'] ?? [])->map(fn ($id) => (int) $id);
            $task->checklistItems()->get()->each(fn ($item) => $item->update(['is_completed' => $completedChecklistIds->contains($item->id)]));

            if ($comment = trim((string) ($data['comment'] ?? ''))) {
                $task->comments()->create(['user_id' => $actor->id, 'body' => $comment]);
            }

            $this->activityLogger->log($task->job->workspace_id, $actor, $task, 'task.updated', "Task {$task->code} updated", ['status' => $status->name]);
            $this->progressCalculator->recalculateForTask($task);

            return $task->fresh(['job.client', 'assignee', 'status', 'priority', 'checklistItems', 'comments.user']);
        });
    }
}
