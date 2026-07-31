<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use DomainException;

class TaskPackActivator
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ProgressCalculator $progress,
        private readonly ActivityLogger $activities,
    ) {}

    /**
     * Reconcile the exact current Phase Task Pack and document requirement with
     * the Job Tasks. Document requirements are represented by real Tasks so
     * users can upload the required file directly from the Task drawer.
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
            ->get(['id', 'task_pack_item_id', 'document_category_id', 'created_at']);
        $createdOrRestored = $this->synchronizePhaseDocumentTask(
            $job,
            $phase,
            $initialStatusId,
            $completedStatusId,
            $phaseDocuments,
            $actor,
            $source,
        );

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
            $hasRequiredDocument = $item->document_category_id
                && $phaseDocuments->contains(fn ($document): bool =>
                    (int) $document->task_pack_item_id === (int) $item->id
                    && (int) $document->document_category_id === (int) $item->document_category_id
                );
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
                'due_date' => null, // Due dates are now set manually from the Task details page.
                'progress' => $hasRequiredDocument && $completedStatusId
                    ? 100
                    : $this->initialProgress($initialStatusId),
                'completed_at' => $hasRequiredDocument && $completedStatusId ? now() : null,
                'sort_order' => $index,
                'task_status_id' => $hasRequiredDocument && $completedStatusId
                    ? $completedStatusId
                    : $initialStatusId,
                ...$documentAttributes,
            ]);

            $createdOrRestored++;
            $this->logTaskAdded($job, $createdTask, $actor, $source);
        }

        return $createdOrRestored;
    }

    private function synchronizePhaseDocumentTask(
        FlowJob $job,
        WorkflowPhase $phase,
        ?int $initialStatusId,
        ?int $completedStatusId,
        $phaseDocuments,
        ?User $actor,
        string $source,
    ): int
    {
        $generated = $job->tasks()
            ->withTrashed()
            ->where('workflow_phase_id', $phase->id)
            ->where('document_requirement_source', 'workflow_phase')
            ->orderBy('id')
            ->get();

        if (! $phase->document_category_id || ! $phase->documentCategory) {
            $generated->filter(fn (FlowTask $task) => ! $task->removed_from_job_at && ! $task->trashed())
                ->each(function (FlowTask $task) use ($job, $actor, $source): void {
                    $this->activities->log(
                        (int) $job->workspace_id,
                        $actor,
                        $job,
                        'job.task_removed_by_setup',
                        "Task {$task->code} removed because the phase document requirement was removed",
                        ['task_id' => $task->id, 'source' => $source],
                    );
                    $task->delete();
                });

            return 0;
        }

        $matching = $generated->first(fn (FlowTask $task) => (int) $task->document_category_id === (int) $phase->document_category_id);

        $generated
            ->reject(fn (FlowTask $task) => $matching && (int) $task->id === (int) $matching->id)
            ->filter(fn (FlowTask $task) => ! $task->removed_from_job_at && ! $task->trashed())
            ->each(function (FlowTask $task) use ($job, $actor, $source): void {
                $this->activities->log(
                    (int) $job->workspace_id,
                    $actor,
                    $job,
                    'job.task_removed_by_setup',
                    "Task {$task->code} removed because the phase document requirement changed",
                    ['task_id' => $task->id, 'source' => $source],
                );
                $task->delete();
            });

        if ($matching?->removed_from_job_at) {
            return 0;
        }

        $title = 'Upload '.$phase->documentCategory->name;
        $description = "Upload the required {$phase->documentCategory->name} document for the {$phase->name} phase. The task is completed automatically after the file is uploaded and linked.";
        $hasRequiredDocument = $phaseDocuments->contains(fn ($document): bool =>
            $document->task_pack_item_id === null
            && (int) $document->document_category_id === (int) $phase->document_category_id
        );
        $setupAssigneeId = $job->coordinator_id ?: $job->owner_id;
        $assignmentAttributes = ['setup_assignee_id' => $setupAssigneeId];
        if (! $matching?->assignee_overridden_at) {
            $assignmentAttributes['assignee_id'] = $setupAssigneeId;
        }

        if ($matching) {
            $restored = false;
            if ($matching->trashed()) {
                $matching->restore();
                $matching->forceFill([
                    'task_status_id' => $matching->task_status_id ?: $initialStatusId,
                    'progress' => $matching->task_status_id ? $matching->progress : $this->initialProgress($initialStatusId),
                    'completed_at' => null,
                    'attention_required' => false,
                    'blocker_reason' => null,
                    ...$this->documentTaskState($matching, $hasRequiredDocument, $initialStatusId, $completedStatusId),
                    ...$assignmentAttributes,
                ])->save();
                $restored = true;
                $this->logTaskAdded($job, $matching, $actor, $source, true);
            }

            $previousAssigneeId = $matching->assignee_id ? (int) $matching->assignee_id : null;
            $matching->forceFill([
                'title' => $title,
                'description' => $description,
                'document_category_id' => $phase->document_category_id,
                'document_requirement_source' => 'workflow_phase',
                ...$this->documentTaskState($matching, $hasRequiredDocument, $initialStatusId, $completedStatusId),
                ...$assignmentAttributes,
            ])->saveQuietly();
            $this->logSetupAssignmentChange($job, $matching, $previousAssigneeId, $actor, $source);

            return $restored ? 1 : 0;
        }

        $createdTask = FlowTask::create([
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'task_pack_item_id' => null,
            'document_category_id' => $phase->document_category_id,
            'document_requirement_source' => 'workflow_phase',
            'created_by' => $actor?->id ?: $job->coordinator_id ?: $job->owner_id,
            'assignee_id' => $setupAssigneeId,
            'setup_assignee_id' => $setupAssigneeId,
            'task_status_id' => $hasRequiredDocument && $completedStatusId ? $completedStatusId : $initialStatusId,
            'priority_id' => $job->priority_id,
            'code' => $this->identifiers->task(),
            'title' => $title,
            'description' => $description,
            'due_date' => null, // Required document task due dates are set manually from Task details.
            'progress' => $hasRequiredDocument && $completedStatusId ? 100 : $this->initialProgress($initialStatusId),
            'completed_at' => $hasRequiredDocument && $completedStatusId ? now() : null,
            'sort_order' => 999,
        ]);
        $this->logTaskAdded($job, $createdTask, $actor, $source);

        return 1;
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
        if ($hasRequiredDocument && $completedStatusId) {
            return [
                'task_status_id' => $completedStatusId,
                'progress' => 100,
                'completed_at' => $task?->completed_at ?: now(),
                'attention_required' => false,
                'blocker_reason' => null,
            ];
        }

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
        if ($item->default_assignee_id) {
            $isActiveMember = WorkspaceMembership::query()
                ->where('workspace_id', $job->workspace_id)
                ->where('user_id', $item->default_assignee_id)
                ->where('status', 'active')
                ->exists();

            if ($isActiveMember) {
                return $item->default_assignee_id;
            }
        }

        if ($item->default_department_id) {
            $departmentUserId = WorkspaceMembership::query()
                ->where('workspace_id', $job->workspace_id)
                ->where('department_id', $item->default_department_id)
                ->where('status', 'active')
                ->orderBy('id')
                ->value('user_id');

            if ($departmentUserId) {
                return (int) $departmentUserId;
            }
        }

        return $job->coordinator_id ?: $job->owner_id;
    }
}
