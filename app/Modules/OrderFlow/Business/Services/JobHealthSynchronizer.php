<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use Illuminate\Support\Facades\DB;

class JobHealthSynchronizer
{
    public function __construct(
        private readonly ActivityLogger $activities,
        private readonly TransitionAuditLogger $transitions,
        private readonly FlowNotificationService $notifications,
    ) {}

    /**
     * Recalculate the Job health from its current phase, task blockers and dates.
     * The board never supplies health directly; it is always derived here.
     */
    public function synchronize(FlowJob $job, ?User $actor = null, string $source = 'system'): string
    {
        $job->loadMissing('currentPhase');
        $tasks = $job->tasks()
            ->where('workflow_phase_id', $job->current_phase_id)
            ->with('status:id,code,name')
            ->get();

        [$health, $reason] = $this->derive($job, $tasks);
        $attention = in_array($health, ['blocked', 'needs_attention', 'at_risk', 'delayed'], true);
        $previous = (string) $job->health;
        $previousReason = (string) ($job->health_reason ?? '');

        if ($previous !== $health || $previousReason !== $reason || (bool) $job->attention_required !== $attention) {
            $job->forceFill([
                'health' => $health,
                'health_reason' => $reason ?: null,
                'health_updated_at' => now(),
                'attention_required' => $attention,
            ])->saveQuietly();

            if ($previous !== $health) {
                $this->activities->log(
                    $job->workspace_id,
                    $actor,
                    $job,
                    'job.health_changed',
                    "Job {$job->code} health changed from {$previous} to {$health}",
                    ['from' => $previous, 'to' => $health, 'reason' => $reason, 'source' => $source],
                );
                $this->transitions->log($job, 'job.health', $actor, $source, $previous, $health, $reason);
                DB::afterCommit(fn () => $this->notifications->jobHealthChanged(
                    $job->id,
                    (int) ($actor?->id ?? 0),
                    $previous,
                    $health,
                    $reason,
                    $source,
                ));
            }
        }

        return $health;
    }

    /** @param \Illuminate\Support\Collection<int, FlowTask> $tasks */
    private function derive(FlowJob $job, $tasks): array
    {
        if ($job->status === 'completed' || strtolower((string) $job->currentPhase?->short_name) === 'completed') {
            return ['completed', 'The Job workflow is complete.'];
        }

        $phaseControl = $job->phaseHistory()
            ->where('workflow_phase_id', $job->current_phase_id)
            ->first(['health_override', 'target_date']);

        $healthOverride = strtolower(trim((string) ($phaseControl?->health_override ?? '')));
        if (in_array($healthOverride, ['on_track', 'needs_attention', 'at_risk', 'delayed', 'blocked'], true)) {
            return [$healthOverride, 'Current phase health is manually controlled from Job Details.'];
        }

        $blocked = $tasks->first(function (FlowTask $task): bool {
            $code = strtoupper(trim((string) $task->status?->code));
            $name = strtoupper(trim((string) $task->status?->name));

            return in_array($code, ['BLK', 'ATTN', 'HOLD'], true)
                || in_array($name, ['BLOCKED', 'ATTENTION', 'ON HOLD'], true);
        });
        if ($blocked) {
            return ['blocked', trim((string) $blocked->blocker_reason) ?: "Task {$blocked->code} is blocked."];
        }

        if ($phaseControl?->target_date?->lt(today())) {
            return ['delayed', 'The current phase target date has passed while the phase is still active.'];
        }

        if ($job->delivery_date?->lt(today())) {
            return ['delayed', 'The Job delivery date has passed while the Job is still active.'];
        }

        $attentionTask = $tasks->first(fn (FlowTask $task) => $task->attention_required || filled($task->blocker_reason));
        if ($attentionTask) {
            return ['needs_attention', trim((string) $attentionTask->blocker_reason) ?: "Task {$attentionTask->code} requires attention."];
        }

        $overdue = $tasks->first(fn (FlowTask $task) => ! $task->completed_at && $task->due_date?->lt(today()));
        if ($overdue) {
            return ['at_risk', "Task {$overdue->code} is overdue."];
        }

        if ($job->delivery_date && $job->delivery_date->isBetween(today(), today()->addDays(2)) && (int) $job->progress < 80) {
            return ['at_risk', 'The delivery date is within two days and workflow progress is below 80%.'];
        }

        return ['on_track', 'No active blockers, overdue tasks or delivery risks were detected.'];
    }
}
