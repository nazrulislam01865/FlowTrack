<?php

namespace App\Modules\OrderFlow\Business\Services;

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
    ) {}

    /**
     * Reconcile the exact current Phase Task Pack with the Job Tasks.
     * The Phase and its Task Pack are always reloaded from the database so a
     * stale first-phase relation can never be reused for a later Phase.
     */
    public function activate(FlowJob $job, WorkflowPhase $phase): int
    {
        $phase = WorkflowPhase::query()
            ->whereKey($phase->id)
            ->where('workflow_template_id', $job->workflow_template_id)
            ->with(['taskPack.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->first();

        if (! $phase) {
            throw new DomainException('The selected Workflow Phase does not belong to this Job workflow.');
        }

        if (! $phase->taskPack || ! $phase->taskPack->is_active || (int) $phase->taskPack->workspace_id !== (int) $job->workspace_id) {
            return 0;
        }

        // Remove only generated Tasks that point to a Task Pack other than the
        // one mapped to this exact Phase. Manually created Tasks have no pack item
        // and remain untouched.
        $job->tasks()
            ->where('workflow_phase_id', $phase->id)
            ->whereNotNull('task_pack_item_id')
            ->whereHas('packItem', fn ($query) => $query->where('task_pack_id', '!=', $phase->task_pack_id))
            ->get()
            ->each(fn (FlowTask $task) => $task->delete());

        $items = TaskPackItem::query()
            ->where('task_pack_id', $phase->task_pack_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $existing = $job->tasks()
            ->withTrashed()
            ->where('workflow_phase_id', $phase->id)
            ->whereIn('task_pack_item_id', $items->pluck('id'))
            ->get()
            ->keyBy('task_pack_item_id');

        $initialStatusId = $this->resolveInitialStatusId($job->workspace_id);
        $createdOrRestored = 0;

        foreach ($items as $index => $item) {
            /** @var FlowTask|null $task */
            $task = $existing->get($item->id);

            if ($task) {
                if ($task->trashed() && $item->is_required) {
                    $task->restore();
                    $task->forceFill([
                        'workflow_phase_id' => $phase->id,
                        'task_status_id' => $task->task_status_id ?: $initialStatusId,
                        'completed_at' => null,
                        'attention_required' => false,
                        'blocker_reason' => null,
                        'progress' => $task->task_status_id ? $task->progress : $this->initialProgress($initialStatusId),
                    ])->save();
                    $createdOrRestored++;
                }

                continue;
            }

            FlowTask::create([
                'flow_job_id' => $job->id,
                'workflow_phase_id' => $phase->id,
                'task_pack_item_id' => $item->id,
                'created_by' => $job->coordinator_id ?: $job->owner_id,
                'assignee_id' => $this->resolveAssignee($job, $item),
                'task_status_id' => $initialStatusId,
                'priority_id' => $item->priority_id ?: $job->priority_id,
                'code' => $this->identifiers->task(),
                'title' => $item->title,
                'description' => $item->description,
                'due_date' => now()->addDays($item->due_offset_days)->toDateString(),
                'progress' => $this->initialProgress($initialStatusId),
                'sort_order' => $index,
            ]);

            $createdOrRestored++;
        }

        return $createdOrRestored;
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
