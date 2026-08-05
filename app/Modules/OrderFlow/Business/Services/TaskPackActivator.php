<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use DomainException;
use Illuminate\Support\Facades\DB;

class TaskPackActivator
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ProgressCalculator $progress,
        private readonly ActivityLogger $activities,
        private readonly FlowNotificationService $notifications,
        private readonly JobDocumentRequirementService $documentRequirements,
    ) {}

    /**
     * Reconcile the exact current Phase Task Pack with the Job Tasks.
     * Phase-level document requirements remain in the Documents panel and do
     * not create extra Tasks outside Task Pack Setup.
     */
    public function activate(
        FlowJob $job,
        WorkflowPhase $phase,
        ?User $actor = null,
        string $source = 'task_setup_sync',
    ): int
    {
        if ($job->excludedPhases()->where('workflow_phase_id', $phase->id)->exists()) {
            return 0;
        }

        $phase = WorkflowPhase::query()
            ->whereKey($phase->id)
            ->where('workflow_template_id', $job->workflow_template_id)
            ->with([
                'documentCategory',
                'taskPack.items' => fn ($query) => $query
                    ->with('documentCategory')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->first();

        if (! $phase) {
            throw new DomainException('The selected Workflow Phase does not belong to this Job workflow.');
        }

        $initialStatusId = $this->resolveInitialStatusId($job->workspace_id);
        $completedStatusId = $this->resolveCompletedStatusId($job->workspace_id);
        $phaseDocuments = $job->documents()
            ->where('workflow_phase_id', $phase->id)
            ->get(['id', 'flow_task_id', 'task_pack_item_id', 'document_category_id', 'created_at']);

        // Workflow Phase document requirements stay in the Documents panel only.
        // The Job task table must contain only Task Pack Setup items (plus any
        // true user-created subtasks), so remove legacy auto-generated phase
        // document upload tasks such as "Upload Client Requirement".
        $this->removeWorkflowPhaseDocumentTasks($job, $phase, $actor, $source);
        $createdOrRestored = 0;
        $generatedTaskIds = [];

        if (! $phase->taskPack || ! $phase->taskPack->is_active || (int) $phase->taskPack->workspace_id !== (int) $job->workspace_id) {
            return $createdOrRestored;
        }

        // Remove only generated Tasks that point to a Task Pack other than the
        // one mapped to this exact Phase. Manually created Tasks remain untouched.
        $job->tasks()
            ->where('workflow_phase_id', $phase->id)
            ->whereNotNull('task_pack_item_id')
            ->whereHas('packItem', fn ($query) => $query->where('task_pack_id', '!=', $phase->task_pack_id))
            ->get()
            ->each(function (FlowTask $task) use ($job, $actor, $source): void {
                $this->activities->log(
                    (int) $job->workspace_id,
                    $actor,
                    $job,
                    'job.task_removed_by_setup',
                    "Task {$task->code} removed because its Task Pack is no longer assigned to this phase",
                    ['task_id' => $task->id, 'source' => $source],
                );
                $task->delete();
            });

        $items = TaskPackItem::query()
            ->where('task_pack_id', $phase->task_pack_id)
            ->with('documentCategory')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return $createdOrRestored;
        }

        $existing = $job->tasks()
            ->withTrashed()
            ->where('workflow_phase_id', $phase->id)
            ->whereIn('task_pack_item_id', $items->pluck('id'))
            ->get()
            ->keyBy('task_pack_item_id');

        foreach ($items as $index => $item) {
            /** @var FlowTask|null $task */
            $task = $existing->get($item->id);
            if ($task && $item->document_category_id) {
                $this->linkLegacyEvidenceToTask($job, $phase, $task, $item);
                $phaseDocuments = $job->documents()
                    ->where('workflow_phase_id', $phase->id)
                    ->get(['id', 'flow_task_id', 'task_pack_item_id', 'document_category_id', 'created_at']);
            }
            $hasRequiredDocument = $task && $item->document_category_id
                ? $this->documentRequirements->isTaskRequirementSatisfied($task)
                : false;
            $documentAttributes = [
                'document_category_id' => $item->document_category_id ?: null,
                'document_requirement_source' => $item->document_category_id ? 'task_pack_item' : null,
            ];
            $documentState = $item->document_category_id
                ? $this->documentTaskState($task, $hasRequiredDocument, $initialStatusId, $completedStatusId)
                : [];
            $setupAssigneeId = $this->resolveAssignee($job, $item);
            $assignmentAttributes = ['setup_assignee_id' => $setupAssigneeId];
            if (! $task?->assignee_overridden_at) {
                $assignmentAttributes['assignee_id'] = $setupAssigneeId;
            }

            if ($task) {
                if ($task->removed_from_job_at) {
                    continue;
                }

                if ($task->trashed() && $item->is_required) {
                    $task->restore();
                    $task->forceFill([
                        'workflow_phase_id' => $phase->id,
                        'task_status_id' => $task->task_status_id ?: $initialStatusId,
                        'completed_at' => null,
                        'attention_required' => false,
                        'blocker_reason' => null,
                        'progress' => $task->task_status_id ? $task->progress : $this->initialProgress($initialStatusId),
                        ...$documentAttributes,
                        ...$documentState,
                        ...$assignmentAttributes,
                    ])->save();
                    $createdOrRestored++;
                    $generatedTaskIds[] = (int) $task->id;
                    $this->logTaskAdded($job, $task, $actor, $source, true);
                } elseif (! $task->trashed()) {
                    $previousAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
                    $task->forceFill([
                        ...$documentAttributes,
                        ...$documentState,
                        ...$assignmentAttributes,
                    ])->saveQuietly();
                    $this->logSetupAssignmentChange($job, $task, $previousAssigneeId, $actor, $source);
                }

                continue;
            }

            $createdTask = FlowTask::create([
                'flow_job_id' => $job->id,
                'workflow_phase_id' => $phase->id,
                'task_pack_item_id' => $item->id,
                'created_by' => $actor?->id ?: $job->coordinator_id ?: $job->owner_id,
                'assignee_id' => $setupAssigneeId,
                'setup_assignee_id' => $setupAssigneeId,
                'priority_id' => $item->priority_id ?: $job->priority_id,
                'code' => $this->identifiers->task(),
                'title' => $item->title,
                'description' => $item->description,
                'start_date' => now()->toDateString(),
                'due_date' => null, // Due dates are now set manually from the Task details page.
                'progress' => $this->initialProgress($initialStatusId),
                'completed_at' => null,
                'sort_order' => $index,
                'task_status_id' => $initialStatusId,
                ...$documentAttributes,
            ]);

            if ($item->document_category_id) {
                $this->linkLegacyEvidenceToTask($job, $phase, $createdTask, $item);
            }
            $createdOrRestored++;
            $generatedTaskIds[] = (int) $createdTask->id;
            $this->logTaskAdded($job, $createdTask, $actor, $source);
        }

        // Initial creation and silent legacy/detail reconciliation should not
        // emit a second wave of "new phase task" notifications. Interactive
        // phase/task-setup synchronization still notifies assignees normally.
        if (! in_array($source, ['job_creation', 'job_detail_repair', 'job_tasks_repair'], true) && $generatedTaskIds !== []) {
            $jobId = (int) $job->id;
            $phaseId = (int) $phase->id;
            $actorId = $actor?->id ? (int) $actor->id : null;
            DB::afterCommit(fn () => $this->notifications->phaseTasksGenerated($jobId, $phaseId, $generatedTaskIds, $actorId));
        }

        return $createdOrRestored;
    }

    private function removeWorkflowPhaseDocumentTasks(
        FlowJob $job,
        WorkflowPhase $phase,
        ?User $actor,
        string $source,
    ): void {
        $job->tasks()
            ->where('workflow_phase_id', $phase->id)
            ->whereNull('task_pack_item_id')
            ->where('document_requirement_source', 'workflow_phase')
            ->get()
            ->each(function (FlowTask $task) use ($job, $actor, $source): void {
                $this->activities->log(
                    (int) $job->workspace_id,
                    $actor,
                    $job,
                    'job.task_removed_by_setup',
                    "Task {$task->code} removed because Workflow Phase document requirements are no longer generated as Tasks",
                    ['task_id' => $task->id, 'source' => $source],
                );
                $task->delete();
            });
    }

    private function linkLegacyEvidenceToTask(FlowJob $job, WorkflowPhase $phase, FlowTask $task, TaskPackItem $item): void
    {
        if (! $item->document_category_id) {
            return;
        }

        FlowDocument::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $phase->id)
            ->where('task_pack_item_id', $item->id)
            ->where('document_category_id', $item->document_category_id)
            ->whereNull('flow_task_id')
            ->whereNull('deleted_at')
            ->update(['flow_task_id' => $task->id]);
    }

    private function logTaskAdded(FlowJob $job, FlowTask $task, ?User $actor, string $source, bool $restored = false): void
    {
        $this->activities->log(
            (int) $job->workspace_id,
            $actor,
            $task,
            $restored ? 'task.restored_from_setup' : 'task.created_from_setup',
            $restored
                ? "Task {$task->code} restored from Task Setup for {$job->code}"
                : "Task {$task->code} added from Task Setup to {$job->code}",
            [
                'flow_job_id' => (int) $job->id,
                'workflow_phase_id' => $task->workflow_phase_id,
                'task_pack_item_id' => $task->task_pack_item_id,
                'assignee_id' => $task->assignee_id,
                'setup_assignee_id' => $task->setup_assignee_id,
                'source' => $source,
            ],
        );
    }

    private function logSetupAssignmentChange(
        FlowJob $job,
        FlowTask $task,
        ?int $previousAssigneeId,
        ?User $actor,
        string $source,
    ): void {
        $currentAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
        if ($previousAssigneeId === $currentAssigneeId || $task->assignee_overridden_at) {
            return;
        }

        $names = User::query()
            ->whereIn('id', collect([$previousAssigneeId, $currentAssigneeId])->filter()->all())
            ->pluck('name', 'id');

        $this->activities->log(
            (int) $job->workspace_id,
            $actor,
            $task,
            'task.assignee_synced_from_setup',
            "Task {$task->code} assignee updated from Task Setup",
            [
                'changes' => [[
                    'field' => 'Assignee',
                    'from' => $previousAssigneeId ? ($names[$previousAssigneeId] ?? 'Former assignee') : 'Unassigned',
                    'to' => $currentAssigneeId ? ($names[$currentAssigneeId] ?? 'Assigned user') : 'Unassigned',
                ]],
                'setup_assignee_id' => $task->setup_assignee_id,
                'source' => $source,
            ],
        );
    }

    private function resolveInitialStatusId(int $workspaceId): ?int
    {
        $query = MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->ofType(MasterDataType::TaskStatus->value);

        $preferred = (clone $query)
            ->where('status', 'active')
            ->where(function ($status) {
                $status->whereIn('code', ['READY', 'NS', 'NEW'])
                    ->orWhereIn('name', ['Ready', 'Not Started', 'New']);
            })
            ->orderByRaw("case
                when upper(code) = 'READY' or upper(name) = 'READY' then 1
                when upper(code) = 'NS' or upper(name) = 'NOT STARTED' then 2
                else 3 end")
            ->value('id');

        if ($preferred) {
            return (int) $preferred;
        }

        $active = (clone $query)->where('status', 'active')->orderBy('sort_order')->orderBy('id')->value('id');

        return $active ? (int) $active : ((clone $query)->orderBy('sort_order')->orderBy('id')->value('id') ?: null);
    }

    private function resolveCompletedStatusId(int $workspaceId): ?int
    {
        $id = MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->ofType(MasterDataType::TaskStatus->value)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereIn('code', ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'])
                    ->orWhereRaw('lower(name) in (?, ?, ?)', ['completed', 'complete', 'done']);
            })
            ->orderByRaw("case when upper(code) = 'CMP' then 1 else 2 end")
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function documentTaskState(
        ?FlowTask $task,
        bool $hasRequiredDocument,
        ?int $initialStatusId,
        ?int $completedStatusId,
    ): array {
        // Evidence presence never grants another actor the right to complete a
        // Task. Completion happens only through the assignee-authorized upload
        // or Task status transition path. Missing evidence may still reopen a
        // previously completed Task as a system integrity correction.
        if (! $hasRequiredDocument && $task
            && ($task->completed_at || ($completedStatusId && (int) $task->task_status_id === $completedStatusId))) {
            return [
                'task_status_id' => $initialStatusId,
                'progress' => $this->initialProgress($initialStatusId),
                'completed_at' => null,
                'attention_required' => false,
                'blocker_reason' => null,
            ];
        }

        return [];
    }

    private function initialProgress(?int $statusId): int
    {
        if (! $statusId) {
            return 0;
        }

        return $this->progress->forTaskStatus(MasterRecord::query()->find($statusId));
    }

    private function resolveAssignee(FlowJob $job, TaskPackItem $item): ?int
    {
        if ($activeDefault = $this->activeWorkspaceUserId((int) $job->workspace_id, $item->default_assignee_id)) {
            return $activeDefault;
        }

        if ($item->default_department_id) {
            $departmentUserId = WorkspaceMembership::query()
                ->where('workspace_id', $job->workspace_id)
                ->where('department_id', $item->default_department_id)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->whereHas('workspace', fn ($query) => $query->where('is_active', true))
                ->orderBy('id')
                ->value('user_id');

            if ($departmentUserId) {
                return (int) $departmentUserId;
            }
        }

        return $this->activeWorkspaceUserId((int) $job->workspace_id, $job->coordinator_id)
            ?: $this->activeWorkspaceUserId((int) $job->workspace_id, $job->owner_id);
    }

    private function activeWorkspaceUserId(int $workspaceId, int|string|null $userId): ?int
    {
        $userId = (int) ($userId ?? 0);
        if ($userId <= 0) {
            return null;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->whereHas('workspace', fn ($query) => $query->where('is_active', true))
            ->exists()
                ? $userId
                : null;
    }
}
