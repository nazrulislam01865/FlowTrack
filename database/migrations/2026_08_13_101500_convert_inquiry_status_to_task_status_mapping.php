<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_records')) {
            DB::table('master_records')
                ->where('type', 'inquiry_status')
                ->update(['type' => 'inquiry_task_status']);
        }

        if (Schema::hasTable('master_values')) {
            DB::table('master_values')
                ->where('group_key', 'inquiry_statuses')
                ->update(['group_key' => 'inquiry_task_statuses']);
        }

        // The four former default Inquiry Status rows are replaced by the new
        // eight-row Inquiry Task Status template. Archive only those known
        // defaults; any user-created custom rows remain available as inactive
        // historical values instead of being destroyed.
        if (Schema::hasTable('master_records')) {
            DB::table('master_records')
                ->where('type', 'inquiry_task_status')
                ->whereIn('code', ['IST-001', 'IST-002', 'IST-003', 'IST-004'])
                ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);
        }
        if (Schema::hasTable('master_values')) {
            DB::table('master_values')
                ->where('group_key', 'inquiry_task_statuses')
                ->whereIn('code', ['IST-001', 'IST-002', 'IST-003', 'IST-004'])
                ->delete();
        }

        if (Schema::hasTable('inquiry_tasks')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                if (!Schema::hasColumn('inquiry_tasks', 'inquiry_task_status_id')) {
                    $table->foreignId('inquiry_task_status_id')
                        ->nullable()
                        ->after('status')
                        ->constrained('master_records')
                        ->nullOnDelete();
                }
                if (!Schema::hasColumn('inquiry_tasks', 'needs_attention')) {
                    $table->boolean('needs_attention')->default(false)->after('inquiry_task_status_id');
                }
                if (!Schema::hasColumn('inquiry_tasks', 'attention_reason')) {
                    $table->text('attention_reason')->nullable()->after('needs_attention');
                }
            });
        }

        if (!Schema::hasTable('master_records') || !Schema::hasTable('workspaces')) {
            return;
        }

        $statuses = [
            ['code' => 'IST-005', 'name' => 'Not Started', 'auto' => 'To do', 'attention' => false, 'color' => '#64748B', 'description' => 'Task has not started yet.'],
            ['code' => 'IST-006', 'name' => 'Ready', 'auto' => 'To do', 'attention' => false, 'color' => '#0284C7', 'description' => 'Task is ready to be worked.'],
            ['code' => 'IST-007', 'name' => 'In Progress', 'auto' => 'In Progress', 'attention' => false, 'color' => '#2563EB', 'description' => 'Task is actively being worked.'],
            ['code' => 'IST-008', 'name' => 'In Review', 'auto' => 'In Progress', 'attention' => false, 'color' => '#7C3AED', 'description' => 'Task is being reviewed.'],
            ['code' => 'IST-009', 'name' => 'Waiting', 'auto' => 'In Progress', 'attention' => true, 'color' => '#D97706', 'description' => 'Task is waiting and requires an attention reason.'],
            ['code' => 'IST-010', 'name' => 'Completed', 'auto' => 'Completed', 'attention' => false, 'color' => '#16A34A', 'description' => 'Task is completed.'],
            ['code' => 'IST-011', 'name' => 'Cancelled', 'auto' => 'Cancelled', 'attention' => false, 'color' => '#DC2626', 'description' => 'Task is cancelled.'],
            ['code' => 'IST-012', 'name' => 'Blocked', 'auto' => '__task_status__', 'attention' => true, 'color' => '#DC2626', 'description' => 'Task cannot continue and requires an attention reason.'],
        ];

        $now = now();
        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            // The previous Inquiry Status catalogue is historical after this change.
            // Keep its rows for audit/history but remove them from active selectors.
            DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'inquiry_task_status')
                ->whereNotIn('code', array_column($statuses, 'code'))
                ->update(['status' => 'inactive', 'updated_at' => $now]);

            foreach ($statuses as $index => $status) {
                $metadata = json_encode([
                    'auto_inquiry_status' => $status['auto'],
                    'requires_attention' => $status['attention'],
                ], JSON_UNESCAPED_SLASHES);

                DB::table('master_records')->updateOrInsert(
                    [
                        'workspace_id' => $workspaceId,
                        'type' => 'inquiry_task_status',
                        'code' => $status['code'],
                    ],
                    [
                        'parent_id' => null,
                        'name' => $status['name'],
                        'description' => $status['description'],
                        'color' => Schema::hasColumn('master_records', 'color') ? $status['color'] : null,
                        'metadata' => $metadata,
                        'status' => 'active',
                        'sort_order' => $index + 1,
                        'deleted_at' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                if (Schema::hasTable('master_values')) {
                    DB::table('master_values')->updateOrInsert(
                        ['group_key' => 'inquiry_task_statuses', 'code' => $status['code']],
                        [
                            'name' => $status['name'],
                            'description' => $status['description'],
                            'is_active' => true,
                            'meta' => json_encode([
                                'auto_inquiry_status' => $status['auto'],
                                'requires_attention' => $status['attention'],
                                'color' => $status['color'],
                            ], JSON_UNESCAPED_SLASHES),
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        }

        if (!Schema::hasTable('inquiry_tasks')) {
            return;
        }

        // Normalize the known legacy Inquiry task statuses to the new dedicated
        // Inquiry Task Status catalogue. Unknown historical values are left as-is.
        $aliases = [
            'not started' => 'Not Started',
            'ready' => 'Ready',
            'in progress' => 'In Progress',
            'in review' => 'In Review',
            'review' => 'In Review',
            'revision required' => 'In Review',
            'waiting' => 'Waiting',
            'waiting for client' => 'Waiting',
            'waiting for supplier' => 'Waiting',
            'completed' => 'Completed',
            'done' => 'Completed',
            'cancelled' => 'Cancelled',
            'canceled' => 'Cancelled',
            'blocked' => 'Blocked',
        ];

        DB::table('inquiry_tasks')->orderBy('id')->each(function ($task) use ($aliases, $now): void {
            $canonical = $aliases[mb_strtolower(trim((string) $task->status))] ?? null;
            if (!$canonical) {
                return;
            }

            $workspaceId = DB::table('inquiries')->where('id', $task->inquiry_id)->value('workspace_id');
            if (!$workspaceId) {
                return;
            }

            $record = DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'inquiry_task_status')
                ->where('name', $canonical)
                ->whereNull('deleted_at')
                ->first(['id', 'metadata']);

            if (!$record) {
                return;
            }

            $metadata = is_string($record->metadata) ? (json_decode($record->metadata, true) ?: []) : (array) $record->metadata;
            DB::table('inquiry_tasks')->where('id', $task->id)->update([
                'status' => $canonical,
                'inquiry_task_status_id' => $record->id,
                'needs_attention' => (bool) ($metadata['requires_attention'] ?? false),
                'attention_reason' => null,
                'updated_at' => $now,
            ]);
        });

        // Existing Inquiry rows may still contain the former manual status names.
        // Recalculate them once so the UI immediately follows the new mapping.
        DB::table('inquiries')
            ->whereNull('deleted_at')
            ->whereNull('result')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) != 'draft'")
            ->orderBy('id')
            ->each(function ($inquiry) use ($now): void {
                $tasks = DB::table('inquiry_tasks')
                    ->where('inquiry_id', $inquiry->id)
                    ->whereNull('deleted_at')
                    ->orderBy('sequence')
                    ->get(['status', 'inquiry_task_status_id', 'sequence', 'started_at', 'completed_at']);

                $total = $tasks->count();
                $completed = $tasks->whereNotNull('completed_at')->count();
                $currentTask = $tasks
                    ->whereNull('completed_at')
                    ->sortBy(function ($task): string {
                        $startedBucket = $task->started_at ? '0' : '1';
                        $sequence = $task->started_at ? (999999 - (int) $task->sequence) : (int) $task->sequence;
                        return $startedBucket.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
                    })
                    ->first();

                if ($total > 0 && $completed === $total) {
                    $nextStatus = 'Completed';
                    $completedAt = $inquiry->completed_at ?: $now;
                } elseif ($currentTask) {
                    $record = $currentTask->inquiry_task_status_id
                        ? DB::table('master_records')->where('id', $currentTask->inquiry_task_status_id)->first(['name', 'metadata'])
                        : null;
                    $metadata = $record && is_string($record->metadata)
                        ? (json_decode($record->metadata, true) ?: [])
                        : (array) ($record->metadata ?? []);
                    $mapped = trim((string) ($metadata['auto_inquiry_status'] ?? ''));
                    $nextStatus = $mapped === '__task_status__' || $mapped === ''
                        ? trim((string) ($record->name ?? $currentTask->status))
                        : $mapped;
                    $completedAt = null;
                } else {
                    $nextStatus = 'To do';
                    $completedAt = null;
                }

                DB::table('inquiries')->where('id', $inquiry->id)->update([
                    'status' => $nextStatus ?: 'To do',
                    'completed_at' => $completedAt,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('inquiry_tasks')) {
            Schema::table('inquiry_tasks', function (Blueprint $table): void {
                if (Schema::hasColumn('inquiry_tasks', 'inquiry_task_status_id')) {
                    $table->dropConstrainedForeignId('inquiry_task_status_id');
                }
                if (Schema::hasColumn('inquiry_tasks', 'needs_attention')) {
                    $table->dropColumn('needs_attention');
                }
                if (Schema::hasColumn('inquiry_tasks', 'attention_reason')) {
                    $table->dropColumn('attention_reason');
                }
            });
        }

        if (Schema::hasTable('master_records')) {
            DB::table('master_records')
                ->where('type', 'inquiry_task_status')
                ->update(['type' => 'inquiry_status']);
        }

        if (Schema::hasTable('master_values')) {
            DB::table('master_values')
                ->where('group_key', 'inquiry_task_statuses')
                ->update(['group_key' => 'inquiry_statuses']);
        }
    }
};
