<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\TransitionAuditLogger;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use DomainException;
use Illuminate\Support\Facades\DB;

class TaskTransitionAction
{
    public function __construct(
        private readonly ActivityLogger $activities,
        private readonly ProgressCalculator $progress,
        private readonly WorkflowAutomationService $automation,
        private readonly TransitionAuditLogger $transitions,
        private readonly FlowNotificationService $notifications,
    ) {}

    public function execute(FlowTask $task, array $data, User $actor, string $source = 'task_drawer'): FlowTask
    {
        return DB::transaction(function () use ($task, $data, $actor, $source) {
            $task->loadMissing('job.documents', 'status', 'dependency', 'phase', 'packItem.documentCategory');
            $workspaceId = (int) $task->job->workspace_id;
            $statusId = (int) ($data['task_status_id'] ?? 0);

            $status = MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', MasterDataType::TaskStatus->value)
                ->whereKey($statusId)
                ->first();

            if (! $status) {
                throw new DomainException('The selected task status is not configured for this workspace.');
            }

            if ($status->status !== 'active' && (int) $task->task_status_id !== (int) $status->id) {
                throw new DomainException('The selected task status is inactive. Choose an active Task Status.');
            }

            if (array_key_exists('assignee_id', $data) && ! empty($data['assignee_id'])) {
                $isMember = WorkspaceMembership::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('user_id', $data['assignee_id'])
                    ->where('status', 'active')
                    ->exists();
                if (! $isMember) {
                    throw new DomainException('The selected assignee is not an active member of this workspace.');
                }
            }

            $targetIsStarted = $this->matches($status, ['IP', 'IN PROGRESS', 'CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            if ($targetIsStarted && $task->dependency && ! $task->dependency->completed_at) {
                throw new DomainException("Complete dependency {$task->dependency->code} before starting or completing this task.");
            }

            $previousStatusId = (int) ($task->task_status_id ?? 0);
            $previousStatus = $task->status?->name ?? null;
            $previousStatusCode = $task->status?->code ?? null;
            $previousAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
            $previousDueDate = $task->due_date?->toDateString();
            $submittedReason = array_key_exists('blocker_reason', $data)
                ? trim((string) ($data['blocker_reason'] ?? ''))
                : trim((string) ($task->blocker_reason ?? ''));

            $isCompleted = $this->matches($status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            $isBlocked = $this->matches($status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);

            if ($isCompleted && $task->packItem?->document_category_id) {
                // A document from another Phase, another Task Pack item, or an
                // earlier version of the workflow must never satisfy this Task.
                $hasRequiredDocument = $task->job->documents()
                    ->where('workflow_phase_id', $task->workflow_phase_id)
                    ->where('task_pack_item_id', $task->task_pack_item_id)
                    ->where('document_category_id', $task->packItem->document_category_id)
                    ->exists();

                if (! $hasRequiredDocument) {
                    $name = $task->packItem->documentCategory?->name ?: 'configured';
                    throw new DomainException("Upload and link the required {$name} document from the Documents page before completing {$task->code}.");
                }
            }
            $wasBlocked = $this->matches($task->status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);

            if ($isBlocked && $submittedReason === '') {
                throw new DomainException('A blocker or attention reason is required.');
            }

            $explicitAttention = array_key_exists('attention_required', $data);
            $explicitReason = array_key_exists('blocker_reason', $data);

            if ($isCompleted) {
                $attention = false;
                $blockerReason = null;
            } elseif ($isBlocked) {
                $attention = true;
                $blockerReason = $submittedReason;
            } elseif ($wasBlocked && ! $explicitAttention && ! $explicitReason) {
                // Moving a card out of a blocked status on the Task Board is an explicit unblock action.
                $attention = false;
                $blockerReason = null;
            } else {
                $attention = (bool) ($data['attention_required'] ?? $task->attention_required);
                $blockerReason = $submittedReason !== '' ? $submittedReason : null;
            }

            $task->update([
                'task_status_id' => $status->id,
                'assignee_id' => array_key_exists('assignee_id', $data) ? ($data['assignee_id'] ?: null) : $task->assignee_id,
                'due_date' => array_key_exists('due_date', $data) ? ($data['due_date'] ?: null) : $task->due_date,
                'progress' => $this->progress->forTaskStatus($status),
                'attention_required' => $attention,
                'blocker_reason' => $blockerReason,
                'completed_at' => $isCompleted ? ($task->completed_at ?: now()) : null,
            ]);

            if (array_key_exists('completed_checklist_ids', $data)) {
                $completedChecklistIds = collect($data['completed_checklist_ids'] ?? [])->map(fn ($id) => (int) $id);
                $task->checklistItems()->get()->each(
                    fn ($item) => $item->update(['is_completed' => $completedChecklistIds->contains($item->id)]),
                );
            }

            if ($comment = trim((string) ($data['comment'] ?? ''))) {
                $task->comments()->create(['user_id' => $actor->id, 'body' => $comment]);
            }

            $statusChanged = $previousStatusId !== (int) $status->id
                || $this->normalise($previousStatusCode) !== $this->normalise($status->code);
            $event = $statusChanged ? 'task.status_changed' : 'task.updated';
            $description = $statusChanged
                ? "Task {$task->code} moved from ".($previousStatus ?: 'Unassigned')." to {$status->name}"
                : "Task {$task->code} updated";

            $this->activities->log(
                $workspaceId,
                $actor,
                $task,
                $event,
                $description,
                ['from' => $previousStatus, 'to' => $status->name, 'source' => $source, 'blocker_reason' => $blockerReason],
            );

            if ($statusChanged) {
                $this->transitions->log(
                    $task->job,
                    'task.status',
                    $actor,
                    $source,
                    $previousStatus,
                    $status->name,
                    $blockerReason,
                    ['from_code' => $previousStatusCode, 'to_code' => $status->code],
                    task: $task,
                    phase: $task->phase,
                );
            }

            $this->progress->recalculateForTask($task);
            $this->automation->synchronize($task->job, $actor, $source);

            $newDueDate = $task->due_date?->toDateString();
            DB::afterCommit(function () use ($task, $actor, $previousAssigneeId, $previousStatus, $previousDueDate, $newDueDate, $comment): void {
                $this->notifications->taskChanged(
                    $task->id,
                    $actor->id,
                    $previousAssigneeId,
                    $previousStatus,
                );
                $this->notifications->taskDueDateChanged($task->id, $actor->id, $previousDueDate, $newDueDate);
                if (filled($comment)) {
                    $this->notifications->taskCommented($task->id, $actor->id, $comment);
                }
            });

            return $task->fresh(['job.client', 'job.currentPhase', 'assignee', 'status', 'priority', 'phase', 'checklistItems', 'comments.user']);
        });
    }

    /** @param array<int, string> $values */
    private function matches(?MasterRecord $status, array $values): bool
    {
        if (! $status) {
            return false;
        }

        $allowed = array_map(fn (string $value): string => $this->normalise($value), $values);

        return in_array($this->normalise($status->code), $allowed, true)
            || in_array($this->normalise($status->name), $allowed, true);
    }

    private function normalise(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
