<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

trait TracksTaskAssigneePerformance
{
    protected static function bootTracksTaskAssigneePerformance(): void
    {
        static::creating(function (Model $task): void {
            if ($task->getAttribute('assignee_id') && !$task->getAttribute('assignee_assigned_at')) {
                $task->setAttribute('assignee_assigned_at', now());
            }

            if (self::performanceTaskIsCompleted($task)) {
                self::snapshotPerformanceCompletionAssignee($task);
            }
        });

        static::saving(function (Model $task): void {
            if ($task->exists && $task->isDirty('assignee_id')) {
                $task->setAttribute(
                    'assignee_assigned_at',
                    $task->getAttribute('assignee_id') ? now() : null,
                );
            }

            if (self::performanceTaskIsCompleted($task)) {
                // Capture historical completion ownership only when it has not
                // already been stored. Reassigning a completed task therefore
                // never moves the employee's historical completion credit.
                if (!$task->getAttribute('assignee_at_completion')) {
                    self::snapshotPerformanceCompletionAssignee($task);
                }
            } else {
                // A reopened task is open work again. Its next completion will
                // create a fresh ownership snapshot for the then-current assignee.
                $task->setAttribute('assignee_at_completion', null);
                $task->setAttribute('assignee_assigned_at_completion', null);
            }
        });
    }

    private static function snapshotPerformanceCompletionAssignee(Model $task): void
    {
        $assigneeId = $task->getAttribute('assignee_id');
        $task->setAttribute('assignee_at_completion', $assigneeId ?: null);
        $task->setAttribute(
            'assignee_assigned_at_completion',
            $assigneeId
                ? ($task->getAttribute('assignee_assigned_at') ?: $task->getAttribute('created_at') ?: now())
                : null,
        );
    }

    private static function performanceTaskIsCompleted(Model $task): bool
    {
        $status = mb_strtolower(trim((string) $task->getAttribute('status')));
        if (in_array($status, ['cancelled', 'canceled'], true)) return false;

        return $task->getAttribute('completed_at') !== null
            || in_array($status, ['completed', 'complete', 'done'], true);
    }
}
