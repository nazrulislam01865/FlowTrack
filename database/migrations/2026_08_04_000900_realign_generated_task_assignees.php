<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('task_pack_items')
            || !Schema::hasTable('tasks')
            || !Schema::hasTable('flow_jobs')
            || !Schema::hasColumn('task_pack_items', 'default_assignee_id')
            || !Schema::hasColumn('tasks', 'task_pack_task_id')
            || !Schema::hasColumn('tasks', 'assignee_id')
            || !Schema::hasColumn('tasks', 'setup_assignee_id')
        ) {
            return;
        }

        DB::table('task_pack_items')
            ->whereNotNull('default_assignee_id')
            ->orderBy('id')
            ->each(function ($item): void {
                DB::table('tasks')
                    ->where('task_pack_task_id', $item->id)
                    ->orderBy('id')
                    ->each(function ($task) use ($item): void {
                        $followsOldSetup = $task->setup_assignee_id
                            && (int) ($task->assignee_id ?: 0) === (int) $task->setup_assignee_id;

                        $looksLikeLegacyCoordinatorFallback = false;
                        if (!$task->setup_assignee_id) {
                            $coordinatorId = DB::table('flow_jobs')
                                ->where('id', $task->flow_job_id)
                                ->value('coordinator_id');

                            $looksLikeLegacyCoordinatorFallback =
                                !$task->assignee_id
                                || ($coordinatorId && (int) $task->assignee_id === (int) $coordinatorId);
                        }

                        if (!$followsOldSetup && !$looksLikeLegacyCoordinatorFallback) {
                            return;
                        }

                        DB::table('tasks')->where('id', $task->id)->update([
                            'assignee_id' => $item->default_assignee_id,
                            'setup_assignee_id' => $item->default_assignee_id,
                            'updated_at' => now(),
                        ]);
                    });
            });
    }

    public function down(): void
    {
        // Assignment correction is intentionally not reversed. Reverting it
        // would restore the legacy coordinator fallback instead of user choices.
    }
};
