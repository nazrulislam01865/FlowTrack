<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Support\BoardLaneResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderTaskFlagService
{
    public function statusRecords(): Collection
    {
        return app(MasterDataService::class)->active('order_task_status');
    }

    public function statusRecord(?string $status, bool $activeOnly = true): ?MasterRecord
    {
        $status = trim((string) $status);
        if ($status === '') return null;

        $active = $this->statusRecords()->first(
            fn (MasterRecord $record): bool => strcasecmp(trim((string) $record->name), $status) === 0
        );
        if ($active || $activeOnly) return $active;

        return MasterRecord::withTrashed()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('order_task_status')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($status)])
            ->orderByDesc('id')
            ->first();
    }

    public function statusOptions(?string $current = null): Collection
    {
        $rows = $this->statusRecords()
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values();

        $current = trim((string) $current);
        if ($current !== '' && !$rows->contains(fn (string $name) => strcasecmp($name, $current) === 0)) {
            $rows->prepend($current);
        }

        return $rows->values();
    }

    public function defaultStatus(): string
    {
        return (string) ($this->statusOptions()->first() ?: BoardLaneResolver::DEFAULT_NOT_STARTED_STATUS);
    }

    public function preferredStatus(array $aliases, ?string $fallback = null): string
    {
        $records = $this->statusRecords();
        foreach ($aliases as $alias) {
            $match = $records->first(fn (MasterRecord $record) => strcasecmp(trim((string) $record->name), trim((string) $alias)) === 0);
            if ($match) return (string) $match->name;
        }

        return $fallback ?: $this->defaultStatus();
    }

    public function notStartedStatus(): string
    {
        return $this->preferredStatus(['Not Started', 'Not Start'], $this->defaultStatus());
    }

    public function readyStatus(): string
    {
        return $this->preferredStatus(['Ready', 'To do', 'Todo'], $this->notStartedStatus());
    }

    public function completedStatus(): string
    {
        return $this->preferredStatus(['Completed', 'Complete', 'Done'], 'Completed');
    }

    public function cancelledStatus(): string
    {
        return $this->preferredStatus(['Cancelled', 'Canceled'], 'Cancelled');
    }

    public function activeTaskFlags(): Collection
    {
        return app(MasterDataService::class)->active('order_task_flag');
    }

    public function activeOrderFlags(): Collection
    {
        return app(MasterDataService::class)->active('order_flag');
    }

    public function taskFlagForStatus(?string $status): ?MasterRecord
    {
        $statusRecord = $this->statusRecord($status, false);
        $flagId = (int) data_get($statusRecord?->metadata, 'order_task_flag_id', 0);
        if ($flagId <= 0) return null;

        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('order_task_flag')
            ->whereKey($flagId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
    }

    public function taskFlagBySystemKey(string $key): ?MasterRecord
    {
        return $this->activeTaskFlags()->first(
            fn (MasterRecord $flag): bool => strcasecmp((string) data_get($flag->metadata, 'system_key'), $key) === 0
        );
    }

    public function orderFlagForTaskFlag(?MasterRecord $taskFlag): ?MasterRecord
    {
        if (!$taskFlag) return null;
        $orderFlagId = (int) data_get($taskFlag->metadata, 'order_flag_id', 0);
        if ($orderFlagId <= 0) return null;

        // MasterDataService::active('order_flag') already applies workspace,
        // type, active-status and soft-delete constraints and caches the rows.
        // Reuse that bounded collection instead of issuing one SQL query for
        // every task while syncJob() evaluates the current Order flag.
        return $this->activeOrderFlags()->first(
            fn (MasterRecord $flag): bool => (int) $flag->id === $orderFlagId
        );
    }

    public function effectiveTaskFlag(Task $task): ?MasterRecord
    {
        if ($this->isCompleted($task)) return null;

        if ($task->due_date && $task->due_date->lt(app(WorkspaceSettingsService::class)->localToday())) {
            return $this->taskFlagBySystemKey('overdue')
                ?: $this->activeTaskFlags()->first(fn (MasterRecord $flag) => strcasecmp($flag->name, 'Overdue') === 0);
        }

        if ($task->relationLoaded('orderTaskFlag') && $task->orderTaskFlag?->status === 'active') {
            return $task->orderTaskFlag;
        }

        if ($task->order_task_flag_id) {
            $flag = MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('order_task_flag')
                ->whereKey($task->order_task_flag_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->first();
            if ($flag) return $flag;
        }

        return $this->taskFlagForStatus((string) $task->status);
    }

    public function syncTask(Task $task, bool $syncParent = true): Task
    {
        $statusRecord = $this->statusRecord((string) $task->status, false);
        $flag = null;

        if (!$this->isCompleted($task)) {
            if ($task->due_date && $task->due_date->lt(app(WorkspaceSettingsService::class)->localToday())) {
                $flag = $this->taskFlagBySystemKey('overdue')
                    ?: $this->activeTaskFlags()->first(fn (MasterRecord $row) => strcasecmp($row->name, 'Overdue') === 0);
            } else {
                $flag = $this->taskFlagForStatus((string) $task->status);
            }
        }

        $updates = [
            'order_task_status_id' => $statusRecord?->id,
            'order_task_flag_id' => $flag?->id,
            'needs_attention' => $flag !== null,
        ];

        // Keep attention_reason as the user-entered explanation. Old installations
        // stored the flag label here; clear only known legacy flag-label values.
        $currentReason = trim((string) $task->attention_reason);
        if (!$flag) {
            $updates['attention_reason'] = null;
        } elseif ($currentReason !== '' && $task->relationLoaded('orderTaskFlag') && $task->orderTaskFlag
            && strcasecmp($currentReason, (string) $task->orderTaskFlag->name) === 0) {
            $updates['attention_reason'] = null;
        }

        $dirty = false;
        foreach ($updates as $key => $value) {
            if ($task->getAttribute($key) != $value) {
                $dirty = true;
                break;
            }
        }
        if ($dirty) $task->update($updates);

        $task = $task->refresh();

        // Batch workflow synchronization updates many generated tasks in one
        // operation. Re-scanning and re-synchronizing the same parent Order
        // after every individual task turns that batch into repeated parent-
        // wide queries. Callers can defer this work and perform it once after
        // the batch without changing the final persisted state.
        if ($syncParent) {
            $job = $task->job()->first();
            $this->syncJob($job);
            if ($job) app(JobService::class)->syncAutomaticStatus($job);
        }

        return $task;
    }

    public function syncJob(?FlowJob $job): ?FlowJob
    {
        if (!$job) return null;

        // Return a fresh model instead of refreshing the caller's hydrated
        // instance in place. Order detail views attach permission-scoped task
        // relations before rendering; refresh() would clear/reload those
        // relations and can also try to resolve transient relation names.

        if ($job->completed_at || in_array(mb_strtolower(trim((string) $job->status)), ['completed', 'cancelled', 'canceled', 'inactive'], true)) {
            if ($job->order_flag_id || $job->needs_attention || (bool) ($job->attention_requested ?? false)) {
                $job->update([
                    'order_flag_id' => null,
                    'needs_attention' => false,
                    'attention_requested' => false,
                    'attention_reason' => null,
                    'attention_by' => null,
                    'attention_at' => null,
                ]);
            }
            return $job->fresh();
        }

        $tasks = $job->tasks()
            ->whereNull('deleted_at')
            ->whereNull('completed_at')
            ->with(['orderTaskFlag:id,type,name,color,status,sort_order,metadata'])
            ->get();

        $candidates = $tasks->map(function (Task $task): ?array {
            $flag = $this->effectiveTaskFlag($task);
            $orderFlag = $this->orderFlagForTaskFlag($flag);
            if (!$flag || !$orderFlag) return null;

            return [
                'task_flag' => $flag,
                'order_flag' => $orderFlag,
                'rank' => $this->flagRank($flag),
                'task_id' => (int) $task->id,
            ];
        })->filter()->sortBy(fn (array $row) => sprintf('%05d-%010d', $row['rank'], $row['task_id']))->values();

        $orderFlag = data_get($candidates->first(), 'order_flag');
        $orderFlagId = $orderFlag?->id;
        $needsAttention = $orderFlagId !== null;

        if ((int) ($job->order_flag_id ?? 0) !== (int) ($orderFlagId ?? 0) || (bool) $job->needs_attention !== $needsAttention) {
            $job->update([
                'order_flag_id' => $orderFlagId,
                'needs_attention' => $needsAttention,
            ]);
        }

        return $job->fresh();
    }

    public function syncDueTransitions(bool $force = false): int
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $key = 'flowtrack:order-task-flags:due-sync:'.$workspaceId.':'.$today;

        if (!$force && !Cache::add($key, true, now()->addMinutes(5))) return 0;

        $overdue = $this->taskFlagBySystemKey('overdue')
            ?: $this->activeTaskFlags()->first(fn (MasterRecord $flag) => strcasecmp($flag->name, 'Overdue') === 0);
        if (!$overdue) return 0;

        $count = 0;
        Task::query()
            ->whereNull('tasks.completed_at')
            ->whereNull('tasks.deleted_at')
            ->whereHas('job', fn ($job) => $job->whereNull('completed_at')->whereNotIn('status', ['Inactive', 'Cancelled']))
            ->where(function ($query) use ($today, $overdue): void {
                $query->where(function ($late) use ($today, $overdue): void {
                    $late->whereNotNull('due_date')
                        ->where('due_date', '<', $today)
                        ->where(function ($notMarked) use ($overdue): void {
                            $notMarked->whereNull('order_task_flag_id')->orWhere('order_task_flag_id', '!=', $overdue->id);
                        });
                })->orWhere(function ($noLongerLate) use ($today, $overdue): void {
                    $noLongerLate->where('order_task_flag_id', $overdue->id)
                        ->where(function ($date) use ($today): void {
                            $date->whereNull('due_date')->orWhere('due_date', '>=', $today);
                        });
                });
            })
            ->orderBy('tasks.id')
            ->chunkById(200, function ($tasks) use (&$count): void {
                foreach ($tasks as $task) {
                    $this->syncTask($task);
                    $count++;
                }
            }, 'tasks.id', 'id');

        return $count;
    }

    public function syncOpenTasks(?int $limit = null): int
    {
        $count = 0;
        $query = Task::query()
            ->whereNull('completed_at')
            ->whereNull('deleted_at')
            ->whereHas('job', fn ($job) => $job->whereNull('completed_at')->whereNotIn('status', ['Inactive', 'Cancelled']))
            ->orderBy('id');
        if ($limit) $query->limit(max(1, $limit));

        $query->chunkById(200, function ($tasks) use (&$count): void {
            foreach ($tasks as $task) {
                $this->syncTask($task);
                $count++;
            }
        });

        return $count;
    }

    public function labelForTask(Task $task): ?string
    {
        return $this->effectiveTaskFlag($task)?->name;
    }

    public function colorForTask(Task $task): ?string
    {
        $flag = $this->effectiveTaskFlag($task);
        return $flag ? app(MasterDataService::class)->displayColorFor('order_task_flag', $flag->name) : null;
    }

    public function labelForOrder(FlowJob $job): ?string
    {
        if ($job->relationLoaded('orderFlag') && $job->orderFlag?->status === 'active') {
            return trim((string) $job->orderFlag->name);
        }
        if ($job->order_flag_id) {
            $flag = MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('order_flag')
                ->whereKey($job->order_flag_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->first();
            if ($flag) return trim((string) $flag->name);
        }

        $synced = $this->syncJob($job);
        if (!$synced?->order_flag_id) return null;

        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('order_flag')
            ->whereKey($synced->order_flag_id)
            ->where('status', 'active')
            ->value('name');
    }

    public function colorForOrder(FlowJob $job): ?string
    {
        $label = $this->labelForOrder($job);
        return $label ? app(MasterDataService::class)->displayColorFor('order_flag', $label) : null;
    }

    private function flagRank(MasterRecord $flag): int
    {
        if (strcasecmp((string) data_get($flag->metadata, 'system_key'), 'overdue') === 0) return 0;
        return max(1, (int) $flag->sort_order + 10);
    }

    private function isCompleted(Task $task): bool
    {
        if ($task->completed_at !== null || BoardLaneResolver::isCompleted((string) $task->status)) return true;

        return in_array(mb_strtolower(trim((string) $task->status)), ['cancelled', 'canceled', 'inactive'], true);
    }
}
