<?php

namespace App\Support;

use App\Models\Task;

/**
 * Centralized Order task requirement semantics.
 *
 * Task Pack `is_required = false` means the task must not block the normal
 * workflow. Most optional tasks remain visible/actionable in their configured
 * sequence. A small set of explicit branch tasks are conditional and only
 * appear after the workflow activates that branch.
 */
final class OrderTaskRequirement
{
    /**
     * Optional tasks that represent true conditional branches rather than
     * always-available optional work.
     */
    private const CONDITIONAL_AUTOMATION_KEYS = [
        'ART_SAMPLE_APPROVAL',
        'QC_ISSUE',
    ];

    public static function isRequired(Task $task): bool
    {
        return ($task->setupTemplate?->is_required ?? $task->template?->is_required ?? true) !== false;
    }

    public static function isOptional(Task $task): bool
    {
        return ! self::isRequired($task);
    }

    public static function automationKey(Task $task): string
    {
        $key = trim((string) ($task->setupTemplate?->automation_key ?? ''));
        if ($key !== '') return strtoupper($key);

        return match (strtolower(trim((string) $task->title))) {
            'sample approval (when required)' => 'ART_SAMPLE_APPROVAL',
            'resolve qc issue (when needed)' => 'QC_ISSUE',
            'monitor / resolve production issue' => 'PROD_ISSUE',
            default => '',
        };
    }

    public static function isConditionalOptional(Task $task): bool
    {
        return self::isOptional($task)
            && in_array(self::automationKey($task), self::CONDITIONAL_AUTOMATION_KEYS, true);
    }

    public static function isRegularOptional(Task $task): bool
    {
        return self::isOptional($task) && ! self::isConditionalOptional($task);
    }

    /**
     * Sequence position from the saved Task Pack definition.
     */
    public static function sequence(Task $task): int
    {
        return (int) ($task->setupTemplate?->sort_order ?? $task->template?->sequence ?? 999999);
    }
}
