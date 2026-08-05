<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;

/**
 * Ensures every active, non-skipped Job phase has its Task Pack Tasks persisted.
 *
 * New Jobs already materialize all phase Tasks in WorkflowEngine::initialize().
 * This service is the compatibility/self-heal path for older Jobs and partially
 * migrated data. It only invokes TaskPackActivator for phases whose persisted
 * Task Pack item set is actually incomplete or mismatched.
 */
class JobPhaseTaskMaterializer
{
    public function __construct(private readonly TaskPackActivator $taskPackActivator) {}

    /**
     * @return array{created_or_restored:int, phases_checked:int, phases_reconciled:int}
     */
    public function materializeAll(
        FlowJob $job,
        ?User $actor = null,
        string $source = 'job_detail_repair',
    ): array {
        $excludedPhaseIds = $job->excludedPhases()
            ->pluck('workflow_phase_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $skippedPhaseIds = $job->phaseHistory()
            ->where('status', 'skipped')
            ->pluck('workflow_phase_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $startedPhaseId = (int) ($job->started_phase_id ?: $job->current_phase_id ?: 0);
        $startedSequence = $startedPhaseId > 0
            ? (int) (WorkflowPhase::query()->whereKey($startedPhaseId)->value('sequence') ?: 1)
            : 1;

        $phases = WorkflowPhase::query()
            ->where('workflow_template_id', $job->workflow_template_id)
            ->where('is_active', true)
            ->with([
                'taskPack:id,workspace_id,is_active',
                'taskPack.items:id,task_pack_id',
            ])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $persistedByPhase = FlowTask::withTrashed()
            ->where('flow_job_id', $job->id)
            ->whereNotNull('task_pack_item_id')
            ->get(['workflow_phase_id', 'task_pack_item_id'])
            ->groupBy(fn (FlowTask $task) => (int) $task->workflow_phase_id)
            ->map(fn ($rows) => $rows->pluck('task_pack_item_id')->map(fn ($id) => (int) $id)->unique()->values());

        $createdOrRestored = 0;
        $checked = 0;
        $reconciled = 0;

        foreach ($phases as $phase) {
            if ((int) $phase->sequence < $startedSequence
                || in_array((int) $phase->id, $excludedPhaseIds, true)
                || in_array((int) $phase->id, $skippedPhaseIds, true)) {
                continue;
            }

            $checked++;

            $pack = $phase->taskPack;
            $expected = $pack && $pack->is_active && (int) $pack->workspace_id === (int) $job->workspace_id
                ? $pack->items->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()
                : collect();
            $persisted = $persistedByPhase->get((int) $phase->id, collect());

            // No Task Pack and no generated Tasks means there is nothing to repair.
            // A changed/removed Task Pack with stale generated Tasks is reconciled so
            // Job Details cannot display Tasks that no longer belong to the phase.
            $missing = $expected->diff($persisted);
            $stale = $persisted->diff($expected);
            if ($missing->isEmpty() && $stale->isEmpty()) {
                continue;
            }

            $reconciled++;
            $createdOrRestored += $this->taskPackActivator->activate($job, $phase, $actor, $source);
        }

        return [
            'created_or_restored' => $createdOrRestored,
            'phases_checked' => $checked,
            'phases_reconciled' => $reconciled,
        ];
    }
}
