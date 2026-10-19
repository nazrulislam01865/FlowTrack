<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\FlowJobPhaseHistory;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\TaskPackTask;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class JobWorkflowSnapshotService
{
    /**
     * Give a Job its own hidden operational copy of the selected Workflow,
     * phases and Task Packs. Existing operational Tasks/history are remapped
     * when protecting an older Job.
     */
    public function snapshot(FlowJob $job, ?int $sourceWorkflowId = null): FlowJob
    {
        return DB::transaction(function () use ($job, $sourceWorkflowId): FlowJob {
            $job = FlowJob::withTrashed()->findOrFail($job->id);
            $currentWorkflow = Workflow::query()->findOrFail($job->workflow_id);

            if ((bool) ($currentWorkflow->is_snapshot ?? false)
                && (int) ($currentWorkflow->snapshot_job_id ?? 0) === (int) $job->id) {
                return $job->refresh();
            }

            $sourceWorkflow = Workflow::query()
                ->with([
                    'phases.taskPack.items.defaultAssignee',
                    'phases.taskPack.items.defaultDepartment',
                    'phases.taskPack.items.priority',
                    'phases.taskPack.items.documentCategory',
                ])
                ->findOrFail($sourceWorkflowId ?: $job->workflow_id);

            $sourceWorkflowIdentity = (int) ($sourceWorkflow->source_workflow_id ?: $sourceWorkflow->id);
            $currentSourcePhaseId = (int) ($job->source_workflow_phase_id ?: $job->workflow_phase_id);
            $startedSourcePhaseId = (int) ($job->started_from_phase_id ?: $currentSourcePhaseId);

            $snapshotWorkflow = $sourceWorkflow->replicate();
            $snapshotWorkflow->name = $sourceWorkflow->name;
            $snapshotWorkflow->slug = 'job-'.$job->id.'-'.Str::slug($sourceWorkflow->name).'-'.Str::lower(Str::random(6));
            $snapshotWorkflow->is_active = false;
            $snapshotWorkflow->is_snapshot = true;
            $snapshotWorkflow->source_workflow_id = $sourceWorkflowIdentity;
            $snapshotWorkflow->snapshot_job_id = $job->id;
            $snapshotWorkflow->save();

            $packMap = [];
            $itemMap = [];
            foreach ($sourceWorkflow->phases->pluck('taskPack')->filter()->unique('id') as $sourcePack) {
                [$snapshotPack, $packItemMap] = $this->cloneTaskPack($sourcePack, $job);
                $packMap[(int) $sourcePack->id] = (int) $snapshotPack->id;
                $itemMap += $packItemMap;
            }

            $phaseMap = [];
            foreach ($sourceWorkflow->phases->sortBy('sequence') as $sourcePhase) {
                $snapshotPhase = $sourcePhase->replicate();
                $snapshotPhase->workflow_id = $snapshotWorkflow->id;
                $snapshotPhase->workflow_template_id = null;
                $snapshotPhase->source_workflow_phase_id = (int) ($sourcePhase->source_workflow_phase_id ?: $sourcePhase->id);
                $snapshotPhase->task_pack_id = $sourcePhase->task_pack_id
                    ? ($packMap[(int) $sourcePhase->task_pack_id] ?? null)
                    : null;
                $snapshotPhase->save();

                $phaseMap[(int) $sourcePhase->id] = (int) $snapshotPhase->id;
                $phaseMap[(int) ($sourcePhase->source_workflow_phase_id ?: $sourcePhase->id)] = (int) $snapshotPhase->id;
            }

            Task::withTrashed()
                ->where('flow_job_id', $job->id)
                ->orderBy('id')
                ->get()
                ->each(function (Task $task) use ($phaseMap, $itemMap): void {
                    $changes = [];
                    if ($task->workflow_phase_id && isset($phaseMap[(int) $task->workflow_phase_id])) {
                        $changes['workflow_phase_id'] = $phaseMap[(int) $task->workflow_phase_id];
                    }
                    if ($task->task_pack_task_id && isset($itemMap[(int) $task->task_pack_task_id])) {
                        $changes['task_pack_task_id'] = $itemMap[(int) $task->task_pack_task_id];
                    }
                    if ($changes) $task->update($changes);
                });

            FlowJobPhaseHistory::query()
                ->where('flow_job_id', $job->id)
                ->get()
                ->each(function (FlowJobPhaseHistory $history) use ($phaseMap): void {
                    if ($history->workflow_phase_id && isset($phaseMap[(int) $history->workflow_phase_id])) {
                        $history->update(['workflow_phase_id' => $phaseMap[(int) $history->workflow_phase_id]]);
                    }
                });

            $mappedCurrentPhaseId = $phaseMap[$currentSourcePhaseId] ?? $phaseMap[(int) $job->workflow_phase_id] ?? null;
            $mappedStartedPhaseId = $phaseMap[$startedSourcePhaseId] ?? $phaseMap[(int) ($job->started_from_phase_id ?: 0)] ?? $mappedCurrentPhaseId;

            abort_unless($mappedCurrentPhaseId, 422, 'FlowTrack could not snapshot the selected current phase.');

            $job->update([
                'workflow_id' => $snapshotWorkflow->id,
                'source_workflow_id' => $sourceWorkflowIdentity,
                'workflow_phase_id' => $mappedCurrentPhaseId,
                'source_workflow_phase_id' => $currentSourcePhaseId,
                'started_from_phase_id' => $mappedStartedPhaseId,
            ]);

            return $job->refresh();
        });
    }

    /** Protect older Jobs that still point directly at setup records. */
    public function snapshotJobs(iterable $jobIds, ?int $sourceWorkflowId = null): int
    {
        $count = 0;
        foreach (collect($jobIds)->map(fn ($id) => (int) $id)->filter()->unique() as $jobId) {
            $job = FlowJob::withTrashed()->find($jobId);
            if (!$job) continue;

            $job = $this->snapshot($job, $sourceWorkflowId);
            if (!$job->trashed()) {
                app(JobService::class)->syncWorkflowTasks($job, null, true);
            }
            $count++;
        }

        return $count;
    }

    private function cloneTaskPack(TaskPack $sourcePack, FlowJob $job): array
    {
        $sourcePack->loadMissing('items');

        $snapshotPack = $sourcePack->replicate();
        $snapshotPack->name = '[Job '.$job->job_number.'] '.$sourcePack->name;
        $snapshotPack->slug = 'job-'.$job->id.'-pack-'.$sourcePack->id.'-'.Str::lower(Str::random(6));
        if (array_key_exists('code', $sourcePack->getAttributes())) {
            $snapshotPack->code = substr('JOB'.$job->id.'-P'.$sourcePack->id.'-'.Str::upper(Str::random(5)), 0, 40);
        }
        $snapshotPack->is_active = false;
        $snapshotPack->is_snapshot = true;
        $snapshotPack->source_task_pack_id = (int) ($sourcePack->source_task_pack_id ?: $sourcePack->id);
        $snapshotPack->snapshot_job_id = $job->id;
        $snapshotPack->save();

        $itemMap = [];

        // task_pack_items and the legacy task_pack_tasks table intentionally
        // share ids because tasks.task_pack_task_id still points at the legacy
        // table. Lock the current tail rows before reserving ids so concurrent
        // Job creation cannot choose the same shared id.
        TaskPackItem::query()->orderByDesc('id')->lockForUpdate()->first(['id']);
        TaskPackTask::query()->orderByDesc('id')->lockForUpdate()->first(['id']);
        $nextSharedId = max(
            (int) TaskPackItem::query()->max('id'),
            (int) TaskPackTask::query()->max('id')
        ) + 1;

        foreach ($sourcePack->items
            ->sortBy(fn (TaskPackItem $item) => [(int) $item->sort_order, (int) $item->id])
            ->values() as $index => $sourceItem) {
            $sharedId = $nextSharedId++;

            $legacyTask = [
                'id' => $sharedId,
                'task_pack_id' => $snapshotPack->id,
                'source_task_pack_task_id' => (int) ($sourceItem->source_task_pack_item_id ?: $sourceItem->id),
                'title' => $sourceItem->title,
                'sequence' => $index + 1,
                'is_required' => (bool) $sourceItem->is_required,
                'default_department_id' => null,
            ];
            if (Schema::hasColumn('task_pack_tasks', 'color')) {
                $legacyTask['color'] = \App\Support\MasterColor::normalize((string) ($sourceItem->color ?? '')) ?: '#2563EB';
            }
            TaskPackTask::query()->create($legacyTask);

            $snapshotItem = $sourceItem->replicate();
            $snapshotItem->id = $sharedId;
            $snapshotItem->task_pack_id = $snapshotPack->id;
            $snapshotItem->source_task_pack_item_id = (int) ($sourceItem->source_task_pack_item_id ?: $sourceItem->id);
            $snapshotItem->save();

            $itemMap[(int) $sourceItem->id] = $sharedId;
            $itemMap[(int) ($sourceItem->source_task_pack_item_id ?: $sourceItem->id)] = $sharedId;
        }

        return [$snapshotPack, $itemMap];
    }
}
