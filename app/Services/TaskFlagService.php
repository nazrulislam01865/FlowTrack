<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Backwards-compatible facade for Order task flags.
 *
 * Order statuses, task flags and parent Order flags now live in three separate
 * Master Data catalogues. New code should prefer OrderTaskFlagService directly.
 */
class TaskFlagService
{
    public function activeFlags(): Collection
    {
        return app(OrderTaskFlagService::class)->activeTaskFlags();
    }

    public function resolveActive(?string $value): ?MasterRecord
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $normalized = mb_strtolower($value);

        return $this->activeFlags()->first(function (MasterRecord $flag) use ($normalized): bool {
            return mb_strtolower(trim((string) $flag->name)) === $normalized
                || mb_strtolower(trim((string) $flag->code)) === $normalized;
        });
    }

    public function requireActive(?string $value): ?MasterRecord
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        $flag = $this->resolveActive($value);
        if (!$flag) {
            throw ValidationException::withMessages([
                'taskFlag' => 'Select an active Order Task Flag from Master Data.',
            ]);
        }

        return $flag;
    }

    public function defaultActive(): ?MasterRecord
    {
        return $this->activeFlags()->first(
            fn (MasterRecord $flag) => strcasecmp((string) data_get($flag->metadata, 'system_key'), 'requires_attention') === 0
        ) ?? $this->activeFlags()->first();
    }

    public function labelForTask(Task $task): ?string
    {
        return app(OrderTaskFlagService::class)->labelForTask($task);
    }

    public function colorForTask(Task $task): ?string
    {
        return app(OrderTaskFlagService::class)->colorForTask($task);
    }

    public function colorForOrder(FlowJob $job): ?string
    {
        return app(OrderTaskFlagService::class)->colorForOrder($job);
    }

    public function labelForOrder(FlowJob $job): ?string
    {
        return app(OrderTaskFlagService::class)->labelForOrder($job);
    }
}
