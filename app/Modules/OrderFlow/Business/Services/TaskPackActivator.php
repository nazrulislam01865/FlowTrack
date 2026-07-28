<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;

class TaskPackActivator
{
    public function __construct(private readonly IdentifierGenerator $identifiers) {}

    public function activate(FlowJob $job, WorkflowPhase $phase): int
    {
        $phase->loadMissing('taskPack.items');

        if (! $phase->taskPack || $phase->taskPack->items->isEmpty() || ! $phase->taskPack->is_active) {
            return 0;
        }

        $alreadyActivated = $job->tasks()->where('workflow_phase_id', $phase->id)->whereNotNull('task_pack_item_id')->exists();
        if ($alreadyActivated) {
            return 0;
        }

        $readyStatus = MasterRecord::query()
            ->where('workspace_id', $job->workspace_id)
            ->ofType(MasterDataType::TaskStatus->value)
            ->where('code', 'READY')
            ->first();

        foreach ($phase->taskPack->items as $index => $item) {
            FlowTask::create([
                'flow_job_id' => $job->id,
                'workflow_phase_id' => $phase->id,
                'task_pack_item_id' => $item->id,
                'assignee_id' => $this->resolveAssignee($job, $item),
                'task_status_id' => $readyStatus?->id,
                'priority_id' => $item->priority_id ?: $job->priority_id,
                'code' => $this->identifiers->task(),
                'title' => $item->title,
                'description' => $item->description,
                'due_date' => now()->addDays($item->due_offset_days)->toDateString(),
                'progress' => 10,
                'sort_order' => $index,
            ]);
        }

        return $phase->taskPack->items->count();
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

        return $job->coordinator_id;
    }
}
