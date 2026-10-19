<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasTable('documents')) {
            return;
        }

        $taskIds = collect();

        if (Schema::hasTable('task_pack_items')
            && Schema::hasColumn('task_pack_items', 'automation_key')
            && Schema::hasColumn('tasks', 'task_pack_task_id')) {
            $taskIds = $taskIds->merge(
                DB::table('tasks')
                    ->join('task_pack_items', 'task_pack_items.id', '=', 'tasks.task_pack_task_id')
                    ->where('task_pack_items.automation_key', 'ART_PREPARE_UPLOAD')
                    ->pluck('tasks.id')
            );
        }

        // Compatibility for Orders/tasks created before automation keys were
        // added to Task Pack items.
        $taskIds = $taskIds->merge(
            DB::table('tasks')
                ->whereIn(DB::raw('LOWER(TRIM(title))'), [
                    'prepare & upload artwork',
                    'prepare and upload artwork',
                ])
                ->pluck('id')
        )->map(fn ($id) => (int) $id)->unique()->values();

        foreach ($taskIds as $taskId) {
            $documents = DB::table('documents')
                ->where('task_id', $taskId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'version']);

            foreach ($documents as $index => $document) {
                $version = $index + 1;
                if ((int) $document->version === $version) {
                    continue;
                }

                DB::table('documents')
                    ->where('id', $document->id)
                    ->update(['version' => $version]);
            }
        }
    }

    public function down(): void
    {
        // Artwork version numbers are corrected data. Reverting them to the
        // previous filename-based numbering would re-introduce duplicates, so
        // there is intentionally no destructive rollback.
    }
};
