<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records')) return;

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (!Schema::hasColumn('tasks', 'order_task_status_id')) {
                    $table->foreignId('order_task_status_id')
                        ->nullable()
                        ->after('status')
                        ->constrained('master_records')
                        ->nullOnDelete();
                }
                if (!Schema::hasColumn('tasks', 'order_task_flag_id')) {
                    $table->foreignId('order_task_flag_id')
                        ->nullable()
                        ->after('needs_attention')
                        ->constrained('master_records')
                        ->nullOnDelete();
                    $table->index(['order_task_flag_id', 'needs_attention'], 'tasks_order_task_flag_attention_idx');
                }
            });
        }

        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                if (!Schema::hasColumn('flow_jobs', 'order_flag_id')) {
                    $table->foreignId('order_flag_id')
                        ->nullable()
                        ->after('needs_attention')
                        ->constrained('master_records')
                        ->nullOnDelete();
                    $table->index(['order_flag_id', 'needs_attention'], 'flow_jobs_order_flag_attention_idx');
                }
            });
        }

        $workspaceIds = Schema::hasTable('workspaces')
            ? DB::table('workspaces')->pluck('id')
            : collect([(int) config('flowtrack.workspace_id', 1)]);

        $now = now();

        foreach ($workspaceIds as $workspaceId) {
            $workspaceId = (int) $workspaceId;

            $legacyTaskFlags = DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'task_flag')
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $taskFlagMap = [];
            $orderFlagMap = [];

            foreach ($legacyTaskFlags as $index => $legacy) {
                $name = trim((string) $legacy->name);
                if ($name === '') continue;

                $orderFlagId = $this->upsertFlag(
                    $workspaceId,
                    'order_flag',
                    'ORF-'.str_pad((string) ($index + 10), 3, '0', STR_PAD_LEFT),
                    $name,
                    (string) ($legacy->description ?? ''),
                    $legacy->color ?? null,
                    (int) ($legacy->sort_order ?? ($index + 10)),
                    ['source' => 'legacy_task_flag'],
                    $now,
                );

                $taskFlagId = $this->upsertFlag(
                    $workspaceId,
                    'order_task_flag',
                    'OTF-'.str_pad((string) ($index + 10), 3, '0', STR_PAD_LEFT),
                    $name,
                    (string) ($legacy->description ?? ''),
                    $legacy->color ?? null,
                    (int) ($legacy->sort_order ?? ($index + 10)),
                    ['order_flag_id' => $orderFlagId, 'source' => 'legacy_task_flag'],
                    $now,
                );

                $key = mb_strtolower($name);
                $taskFlagMap[$key] = $taskFlagId;
                $orderFlagMap[$key] = $orderFlagId;
            }

            $overdueOrderFlagId = $this->upsertFlag(
                $workspaceId,
                'order_flag',
                'ORF-001',
                'Overdue',
                'Automatically applied to an Order when an unfinished task is past its due date.',
                '#DC2626',
                1,
                ['system_key' => 'overdue'],
                $now,
            );
            $attentionOrderFlagId = $this->upsertFlag(
                $workspaceId,
                'order_flag',
                'ORF-002',
                'Requires attention',
                'Applied to an Order when the current task status is configured to require attention.',
                '#D97706',
                2,
                ['system_key' => 'requires_attention'],
                $now,
            );
            $overdueTaskFlagId = $this->upsertFlag(
                $workspaceId,
                'order_task_flag',
                'OTF-001',
                'Overdue',
                'System task flag applied automatically after the task due date passes.',
                '#DC2626',
                1,
                ['system_key' => 'overdue', 'order_flag_id' => $overdueOrderFlagId],
                $now,
            );
            $attentionTaskFlagId = $this->upsertFlag(
                $workspaceId,
                'order_task_flag',
                'OTF-002',
                'Requires attention',
                'Default status-driven task flag for waiting, blocked or other attention statuses.',
                '#D97706',
                2,
                ['system_key' => 'requires_attention', 'order_flag_id' => $attentionOrderFlagId],
                $now,
            );

            $taskFlagMap['overdue'] = $overdueTaskFlagId;
            $taskFlagMap['requires attention'] = $attentionTaskFlagId;
            $orderFlagMap['overdue'] = $overdueOrderFlagId;
            $orderFlagMap['requires attention'] = $attentionOrderFlagId;

            $legacyStatuses = DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'task_status')
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($legacyStatuses->isEmpty()) {
                $legacyStatuses = collect([
                    (object) ['name' => 'Not Started', 'description' => 'Task has not started yet.', 'color' => '#64748B', 'sort_order' => 1, 'status' => 'active'],
                    (object) ['name' => 'Ready', 'description' => 'Task is ready to begin.', 'color' => '#0284C7', 'sort_order' => 2, 'status' => 'active'],
                    (object) ['name' => 'In Progress', 'description' => 'Task is currently being worked.', 'color' => '#2563EB', 'sort_order' => 3, 'status' => 'active'],
                    (object) ['name' => 'Waiting', 'description' => 'Task is waiting and requires attention.', 'color' => '#D97706', 'sort_order' => 4, 'status' => 'active'],
                    (object) ['name' => 'Blocked', 'description' => 'Task cannot continue and requires attention.', 'color' => '#DC2626', 'sort_order' => 5, 'status' => 'active'],
                    (object) ['name' => 'Completed', 'description' => 'Task is completed.', 'color' => '#16A34A', 'sort_order' => 6, 'status' => 'active'],
                    (object) ['name' => 'Cancelled', 'description' => 'Task is cancelled.', 'color' => '#64748B', 'sort_order' => 7, 'status' => 'active'],
                ]);
            }

            $statusMap = [];
            $statusTaskFlagMap = [];
            foreach ($legacyStatuses as $index => $legacy) {
                $name = trim((string) $legacy->name);
                if ($name === '') continue;
                $normalized = mb_strtolower($name);
                $flagId = $this->statusRequiresAttention($name) ? $attentionTaskFlagId : null;

                $metadata = ['source' => 'legacy_task_status'];
                if ($flagId) $metadata['order_task_flag_id'] = $flagId;

                $id = $this->upsertFlag(
                    $workspaceId,
                    'order_task_status',
                    'OTS-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    $name,
                    (string) ($legacy->description ?? ''),
                    $legacy->color ?? null,
                    (int) ($legacy->sort_order ?? ($index + 1)),
                    $metadata,
                    $now,
                    (string) ($legacy->status ?? 'active'),
                );
                $statusMap[$normalized] = $id;
                $statusTaskFlagMap[$normalized] = $flagId;
            }

            if (Schema::hasTable('tasks')) {
                DB::table('tasks')
                    ->join('flow_jobs', 'flow_jobs.id', '=', 'tasks.flow_job_id')
                    ->whereNull('tasks.deleted_at')
                    ->whereNull('tasks.order_task_status_id')
                    ->select([
                        'tasks.id', 'tasks.status', 'tasks.needs_attention', 'tasks.task_flag_id',
                        'tasks.attention_reason', 'tasks.due_date', 'tasks.completed_at',
                        'flow_jobs.status as job_status', 'flow_jobs.completed_at as job_completed_at',
                    ])
                    ->orderBy('tasks.id')
                    ->chunkById(250, function ($tasks) use ($statusMap, $statusTaskFlagMap, $taskFlagMap, $overdueTaskFlagId): void {
                        $today = now()->toDateString();
                        foreach ($tasks as $task) {
                            $normalizedStatus = mb_strtolower(trim((string) $task->status));
                            $statusId = $statusMap[$normalizedStatus] ?? null;
                            $flagId = null;
                            $taskStatus = mb_strtolower(trim((string) $task->status));
                            $jobStatus = mb_strtolower(trim((string) $task->job_status));
                            $completed = $task->completed_at !== null
                                || $task->job_completed_at !== null
                                || in_array($taskStatus, ['completed', 'cancelled', 'canceled', 'inactive'], true)
                                || in_array($jobStatus, ['completed', 'cancelled', 'canceled', 'inactive'], true);

                            if (!$completed && $task->due_date && (string) $task->due_date < $today) {
                                $flagId = $overdueTaskFlagId;
                            } elseif (!$completed) {
                                // New Order flags are status-driven. Apply the mapped flag to
                                // every existing task immediately, even if an older row never
                                // had needs_attention populated correctly.
                                $flagId = $statusTaskFlagMap[$normalizedStatus] ?? null;

                                // Preserve an explicit legacy flag only when this status has no
                                // mapping, so upgrades do not silently lose historical intent.
                                if (!$flagId && $task->needs_attention) {
                                    $legacyName = trim((string) $task->attention_reason);
                                    if ($task->task_flag_id) {
                                        $legacyName = trim((string) DB::table('master_records')->where('id', $task->task_flag_id)->value('name')) ?: $legacyName;
                                    }
                                    $flagId = $taskFlagMap[mb_strtolower($legacyName)] ?? $taskFlagMap['requires attention'] ?? null;
                                }
                            }

                            DB::table('tasks')->where('id', $task->id)->update([
                                'order_task_status_id' => $statusId,
                                'order_task_flag_id' => $flagId,
                                'needs_attention' => $flagId !== null,
                            ]);
                        }
                    }, 'tasks.id', 'id');

                // Recalculate Order-level flags from the newly separated task flags.
                $taskFlagRules = DB::table('master_records')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'order_task_flag')
                    ->whereNull('deleted_at')
                    ->get(['id', 'sort_order', 'metadata'])
                    ->mapWithKeys(function ($row): array {
                        $metadata = json_decode((string) $row->metadata, true) ?: [];
                        $isOverdue = strcasecmp((string) ($metadata['system_key'] ?? ''), 'overdue') === 0;

                        return [(int) $row->id => [
                            'order_flag_id' => (int) ($metadata['order_flag_id'] ?? 0),
                            'rank' => $isOverdue ? 0 : max(1, (int) $row->sort_order + 10),
                        ]];
                    });

                DB::table('flow_jobs')->whereNull('deleted_at')->orderBy('id')->chunkById(200, function ($jobs) use ($taskFlagRules): void {
                    foreach ($jobs as $job) {
                        $jobStatus = mb_strtolower(trim((string) $job->status));
                        if ($job->completed_at !== null || in_array($jobStatus, ['completed', 'cancelled', 'canceled', 'inactive'], true)) {
                            DB::table('flow_jobs')->where('id', $job->id)->update([
                                'order_flag_id' => null,
                                'needs_attention' => false,
                            ]);
                            continue;
                        }

                        $candidate = DB::table('tasks')
                            ->where('flow_job_id', $job->id)
                            ->whereNull('deleted_at')
                            ->whereNull('completed_at')
                            ->whereNotNull('order_task_flag_id')
                            ->get(['id', 'order_task_flag_id'])
                            ->map(function ($task) use ($taskFlagRules): ?array {
                                $rule = $taskFlagRules[(int) $task->order_task_flag_id] ?? null;
                                if (!$rule || empty($rule['order_flag_id'])) return null;

                                return [
                                    'order_flag_id' => (int) $rule['order_flag_id'],
                                    'rank' => (int) $rule['rank'],
                                    'task_id' => (int) $task->id,
                                ];
                            })
                            ->filter()
                            ->sortBy(fn (array $row) => sprintf('%07d-%010d', $row['rank'], $row['task_id']))
                            ->first();

                        $orderFlagId = (int) ($candidate['order_flag_id'] ?? 0);

                        DB::table('flow_jobs')->where('id', $job->id)->update([
                            'order_flag_id' => $orderFlagId ?: null,
                            'needs_attention' => $orderFlagId > 0,
                        ]);
                    }
                }, 'id');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_jobs') && Schema::hasColumn('flow_jobs', 'order_flag_id')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->dropIndex('flow_jobs_order_flag_attention_idx');
                $table->dropConstrainedForeignId('order_flag_id');
            });
        }
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (Schema::hasColumn('tasks', 'order_task_flag_id')) {
                    $table->dropIndex('tasks_order_task_flag_attention_idx');
                    $table->dropConstrainedForeignId('order_task_flag_id');
                }
                if (Schema::hasColumn('tasks', 'order_task_status_id')) {
                    $table->dropConstrainedForeignId('order_task_status_id');
                }
            });
        }

        if (Schema::hasTable('master_values')) {
            DB::table('master_values')
                ->whereIn('group_key', ['order_task_statuses', 'order_task_flags', 'order_flags'])
                ->delete();
        }
        if (Schema::hasTable('master_records')) {
            DB::table('master_records')->whereIn('type', ['order_task_status', 'order_task_flag', 'order_flag'])->delete();
        }
    }

    private function upsertFlag(
        int $workspaceId,
        string $type,
        string $code,
        string $name,
        string $description,
        ?string $color,
        int $sortOrder,
        array $metadata,
        $now,
        string $status = 'active',
    ): int {
        $existing = DB::table('master_records')
            ->where('workspace_id', $workspaceId)
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $currentMetadata = json_decode((string) ($existing->metadata ?? ''), true) ?: [];
            DB::table('master_records')->where('id', $existing->id)->update([
                'description' => filled($existing->description ?? null) ? $existing->description : ($description ?: null),
                'color' => $existing->color ?: $color,
                'metadata' => json_encode(array_merge($currentMetadata, $metadata), JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);
            return (int) $existing->id;
        }

        return (int) DB::table('master_records')->insertGetId([
            'workspace_id' => $workspaceId,
            'parent_id' => null,
            'type' => $type,
            'code' => $code,
            'name' => $name,
            'description' => $description ?: null,
            'color' => $color,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'status' => $status === 'inactive' ? 'inactive' : 'active',
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    private function statusRequiresAttention(string $status): bool
    {
        $normalized = mb_strtolower(trim($status));

        return str_starts_with($normalized, 'waiting')
            || str_contains($normalized, 'attention')
            || in_array($normalized, ['blocked', 'on hold', 'delayed', 'at risk'], true)
            || str_contains($normalized, 'revision');
    }
};
