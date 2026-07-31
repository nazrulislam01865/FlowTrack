<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
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
        private readonly FlowAuthorizationService $authorization,
    ) {}

    public function execute(FlowTask $task, array $data, User $actor, string $source = 'task_drawer'): FlowTask
    {
        return DB::transaction(function () use ($task, $data, $actor, $source) {
            $task->loadMissing(
                'job.documents', 'job.owner', 'job.coordinator', 'job.members.user',
                'status', 'assignee', 'priority', 'dependency', 'phase', 'packItem.documentCategory', 'documentCategory',
            );
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

            $previousTitle = $task->title;
            $previousDescription = $task->description ?: null;
            $previousPriorityId = $task->priority_id ? (int) $task->priority_id : null;
            $previousPriority = $task->priority?->name ?: 'Unassigned';
            $previousStatusId = (int) ($task->task_status_id ?? 0);
            $previousStatus = $task->status?->name;
            $previousStatusCode = $task->status?->code;
            $previousAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
            $previousAssignee = $task->assignee?->name ?: 'Unassigned';
            $previousDueDate = $task->due_date?->toDateString();
            $previousProgress = (int) $task->progress;
            $previousAttention = (bool) $task->attention_required;
            $previousBlockerReason = $task->blocker_reason ?: null;
            $submittedReason = array_key_exists('blocker_reason', $data)
                ? trim((string) ($data['blocker_reason'] ?? ''))
                : trim((string) ($task->blocker_reason ?? ''));

            $isCompleted = $this->matches($status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            $isBlocked = $this->matches($status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);

            $documentCategoryId = $task->document_category_id ?: $task->packItem?->document_category_id;
            if ($isCompleted && $documentCategoryId) {
                $hasRequiredDocument = $task->job->documents()
                    ->where('workflow_phase_id', $task->workflow_phase_id)
                    ->where('document_category_id', $documentCategoryId)
                    ->when(
                        $task->document_requirement_source === 'workflow_phase',
                        fn ($query) => $query->whereNull('task_pack_item_id'),
                        fn ($query) => $query->where('task_pack_item_id', $task->task_pack_item_id),
                    )
                    ->exists();

                if (! $hasRequiredDocument) {
                    $name = $task->documentCategory?->name ?: $task->packItem?->documentCategory?->name ?: 'configured';
                    throw new DomainException("Upload the required {$name} document from this Task before completing {$task->code}.");
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
                $attention = false;
                $blockerReason = null;
            } else {
                $attention = (bool) ($data['attention_required'] ?? $task->attention_required);
                $blockerReason = $submittedReason !== '' ? $submittedReason : null;
            }

            $nextAssigneeId = array_key_exists('assignee_id', $data)
                ? ($data['assignee_id'] ? (int) $data['assignee_id'] : null)
                : ($task->assignee_id ? (int) $task->assignee_id : null);
            $nextPriorityId = array_key_exists('priority_id', $data)
                ? ($data['priority_id'] ? (int) $data['priority_id'] : null)
                : ($task->priority_id ? (int) $task->priority_id : null);
            $nextPriority = $nextPriorityId
                ? MasterRecord::query()->whereKey($nextPriorityId)->where('workspace_id', $workspaceId)->first()
                : null;
            $assigneeChanged = $previousAssigneeId !== $nextAssigneeId;
            $assignmentOverride = [];

            if ($assigneeChanged && $this->authorization->isAdministrator($actor)) {
                $matchesSetup = (int) ($nextAssigneeId ?? 0) === (int) ($task->setup_assignee_id ?? 0);
                $assignmentOverride = [
                    'assignee_overridden_at' => $matchesSetup ? null : now(),
                    'assignee_overridden_by' => $matchesSetup ? null : $actor->id,
                ];
            }

            $task->update([
                'title' => array_key_exists('title', $data) ? trim((string) $data['title']) : $task->title,
                'description' => array_key_exists('description', $data) ? (blank($data['description']) ? null : trim((string) $data['description'])) : $task->description,
                'task_status_id' => $status->id,
                'assignee_id' => $nextAssigneeId,
                'priority_id' => $nextPriorityId,
                'due_date' => array_key_exists('due_date', $data) ? ($data['due_date'] ?: null) : $task->due_date,
                'progress' => $this->progress->forTaskStatus($status),
                'attention_required' => $attention,
                'blocker_reason' => $blockerReason,
                'completed_at' => $isCompleted ? ($task->completed_at ?: now()) : null,
                ...$assignmentOverride,
            ]);

            // Kept for API compatibility with older clients. The Task drawer no longer displays a checklist.
            if (array_key_exists('completed_checklist_ids', $data)) {
                $completedChecklistIds = collect($data['completed_checklist_ids'] ?? [])->map(fn ($id) => (int) $id);
                $task->checklistItems()->get()->each(
                    fn ($item) => $item->update(['is_completed' => $completedChecklistIds->contains($item->id)]),
                );
            }

            $comment = trim((string) ($data['comment'] ?? ''));
            if ($comment !== '') {
                $task->comments()->create(['user_id' => $actor->id, 'body' => $comment]);
            }

            $task->load(['status', 'assignee', 'priority']);
            $newDueDate = $task->due_date?->toDateString();
            $newAssignee = $task->assignee?->name ?: 'Unassigned';
            $newPriority = $task->priority?->name ?: 'Unassigned';
            $statusChanged = $previousStatusId !== (int) $status->id
                || $this->normalise($previousStatusCode) !== $this->normalise($status->code);

            $changes = collect([
                $previousTitle !== $task->title ? ['field' => 'Title', 'from' => $previousTitle, 'to' => $task->title] : null,
                $previousDescription !== ($task->description ?: null) ? ['field' => 'Description', 'from' => $previousDescription ?: 'Empty', 'to' => $task->description ?: 'Empty'] : null,
                $statusChanged ? ['field' => 'Status', 'from' => $previousStatus ?: 'Unassigned', 'to' => $status->name] : null,
                $previousAssigneeId !== ($task->assignee_id ? (int) $task->assignee_id : null) ? ['field' => 'Assignee', 'from' => $previousAssignee, 'to' => $newAssignee] : null,
                $previousPriorityId !== ($task->priority_id ? (int) $task->priority_id : null) ? ['field' => 'Priority', 'from' => $previousPriority, 'to' => $newPriority] : null,
                $previousDueDate !== $newDueDate ? ['field' => 'Due date', 'from' => $previousDueDate ?: 'Not set', 'to' => $newDueDate ?: 'Not set'] : null,
                $previousProgress !== (int) $task->progress ? ['field' => 'Progress', 'from' => "{$previousProgress}%", 'to' => "{$task->progress}%"] : null,
                $previousAttention !== (bool) $task->attention_required ? ['field' => 'Attention needed', 'from' => $previousAttention ? 'Yes' : 'No', 'to' => $task->attention_required ? 'Yes' : 'No'] : null,
                $previousBlockerReason !== ($task->blocker_reason ?: null) ? ['field' => 'Blocker reason', 'from' => $previousBlockerReason ?: 'None', 'to' => $task->blocker_reason ?: 'None'] : null,
            ])->filter()->values()->all();

            $event = $statusChanged ? 'task.status_changed' : ($comment !== '' && empty($changes) ? 'task.commented' : 'task.updated');
            $changedFields = collect($changes)->pluck('field')->join(', ');
            $description = $changedFields !== ''
                ? "Task {$task->code} updated: {$changedFields}"
                : ($comment !== '' ? "Comment added to Task {$task->code}" : "Task {$task->code} updated");

            $this->activities->log(
                $workspaceId,
                $actor,
                $task,
                $event,
                $description,
                [
                    'from' => $previousStatus,
                    'to' => $status->name,
                    'source' => $source,
                    'blocker_reason' => $blockerReason,
                    'changes' => $changes,
                    'comment_added' => $comment !== '',
                ],
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

            $allowedMentionIds = collect([
                $task->job->owner_id,
                $task->job->coordinator_id,
                $task->assignee_id,
            ])->merge($task->job->members->pluck('user_id'))
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique();
            $mentionedUserIds = collect($data['mentioned_user_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->intersect($allowedMentionIds)
                ->reject(fn (int $id): bool => $id === (int) $actor->id)
                ->unique()
                ->values()
                ->all();

            $this->progress->recalculateForTask($task);
            $this->automation->synchronize($task->job, $actor, $source);

            DB::afterCommit(function () use ($task, $actor, $previousAssigneeId, $previousStatus, $previousDueDate, $newDueDate, $comment, $mentionedUserIds): void {
                $this->notifications->taskChanged(
                    $task->id,
                    $actor->id,
                    $previousAssigneeId,
                    $previousStatus,
                );
                $this->notifications->taskDueDateChanged($task->id, $actor->id, $previousDueDate, $newDueDate);
                if ($comment !== '') {
                    $this->notifications->taskCommented($task->id, $actor->id, $comment, $mentionedUserIds);
                    $this->notifications->taskMentioned($task->id, $actor->id, $comment, $mentionedUserIds);
                }
            });

            return $task->fresh([
                'job.client', 'job.currentPhase', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                'assignee', 'status', 'priority', 'phase', 'packItem.documentCategory', 'documentCategory', 'checklistItems', 'comments.user',
                'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
            ]);
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
