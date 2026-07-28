<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;

class ProgressCalculator
{
    /**
     * Task progress is derived from the selected workflow status.
     * This keeps cards, drawers, boards, reports and job progress consistent.
     */
    public function forTaskStatus(MasterRecord|string|null $status): int
    {
        $code = $status instanceof MasterRecord ? $status->code : (string) $status;

        return match (strtoupper($code)) {
            'NS', 'NOT STARTED', 'DRAFT' => 0,
            'READY' => 10,
            'REV', 'REVISION REQUIRED' => 35,
            'IP', 'IN PROGRESS' => 50,
            'BLK', 'BLOCKED', 'HOLD', 'ON HOLD' => 50,
            'WC', 'WAITING FOR CLIENT',
            'WS', 'WAITING FOR SUPPLIER',
            'WIA', 'WAITING FOR INTERNAL APPROVAL' => 65,
            'CMP', 'COMPLETED' => 100,
            default => 0,
        };
    }

    public function recalculateJob(FlowJob $job): int
    {
        $job->loadMissing([
            'workflow.phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
            'currentPhase',
        ]);

        if ($job->status === 'draft') {
            $progress = 0;
        } elseif ($job->status === 'completed' || $job->currentPhase?->short_name === 'Completed') {
            $progress = 100;
        } else {
            $phases = $job->workflow?->phases ?? collect();
            $phaseCount = max(1, $phases->count());
            $currentIndex = max(0, $phases->search(fn ($phase) => $phase->id === $job->current_phase_id));
            $phaseSpan = 100 / max(1, $phaseCount - 1);
            $base = $currentIndex * $phaseSpan;

            $taskAverage = (float) $job->tasks()
                ->where('workflow_phase_id', $job->current_phase_id)
                ->avg('progress');

            $currentPhaseContribution = $taskAverage > 0
                ? ($taskAverage / 100) * $phaseSpan
                : 0;

            $progress = min(99, (int) round($base + $currentPhaseContribution));
        }

        if ((int) $job->progress !== $progress) {
            $job->forceFill(['progress' => $progress])->saveQuietly();
        }

        return $progress;
    }

    public function recalculateForTask(FlowTask $task): int
    {
        $task->loadMissing('job');

        return $this->recalculateJob($task->job);
    }
}
