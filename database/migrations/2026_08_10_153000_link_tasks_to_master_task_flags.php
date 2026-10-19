<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tasks') || !Schema::hasTable('master_records')) return;

        if (!Schema::hasColumn('tasks', 'task_flag_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreignId('task_flag_id')
                    ->nullable()
                    ->after('needs_attention')
                    ->constrained('master_records')
                    ->nullOnDelete();
                $table->index(['task_flag_id', 'needs_attention']);
            });
        }

        $workspaceId = (int) config('flowtrack.workspace_id', 1);
        $flags = DB::table('master_records')
            ->where('workspace_id', $workspaceId)
            ->where('type', 'task_flag')
            ->whereNull('deleted_at')
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'status']);

        if ($flags->isEmpty()) return;

        $byName = $flags->keyBy(fn ($flag) => mb_strtolower(trim((string) $flag->name)));
        $default = $flags->first(fn ($flag) => $flag->status === 'active' && strcasecmp(trim((string) $flag->name), 'Management attention') === 0)
            ?? $flags->first(fn ($flag) => $flag->status === 'active')
            ?? $flags->first();

        DB::table('tasks')
            ->where('needs_attention', true)
            ->whereNull('task_flag_id')
            ->orderBy('id')
            ->chunkById(250, function ($tasks) use ($byName, $default): void {
                foreach ($tasks as $task) {
                    $reason = trim((string) ($task->attention_reason ?? ''));
                    $flag = $reason !== '' ? $byName->get(mb_strtolower($reason)) : null;
                    $flag ??= $default;
                    if (!$flag) continue;

                    DB::table('tasks')->where('id', $task->id)->update([
                        'task_flag_id' => $flag->id,
                        'attention_reason' => $flag->name,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        if (!Schema::hasTable('tasks') || !Schema::hasColumn('tasks', 'task_flag_id')) return;

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['task_flag_id', 'needs_attention']);
            $table->dropConstrainedForeignId('task_flag_id');
        });
    }
};
