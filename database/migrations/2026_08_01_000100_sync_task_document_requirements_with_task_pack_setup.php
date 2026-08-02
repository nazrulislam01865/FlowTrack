<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_tasks')
            || ! Schema::hasTable('task_pack_items')
            || ! Schema::hasColumn('flow_tasks', 'task_pack_item_id')
            || ! Schema::hasColumn('flow_tasks', 'document_category_id')
            || ! Schema::hasColumn('flow_tasks', 'document_requirement_source')
            || ! Schema::hasColumn('task_pack_items', 'document_category_id')) {
            return;
        }

        DB::table('flow_tasks')
            ->join('task_pack_items', 'task_pack_items.id', '=', 'flow_tasks.task_pack_item_id')
            ->whereNull('flow_tasks.deleted_at')
            ->select([
                'flow_tasks.id as id',
                'task_pack_items.document_category_id as setup_document_category_id',
            ])
            ->orderBy('flow_tasks.id')
            ->chunkById(500, function ($tasks): void {
                foreach ($tasks as $task) {
                    $categoryId = $task->setup_document_category_id ? (int) $task->setup_document_category_id : null;

                    DB::table('flow_tasks')->where('id', $task->id)->update([
                        'document_category_id' => $categoryId,
                        'document_requirement_source' => $categoryId ? 'task_pack_item' : null,
                        'updated_at' => now(),
                    ]);
                }
            }, 'flow_tasks.id', 'id');
    }

    public function down(): void
    {
        // Data sync only. Do not restore stale document labels on tasks.
    }
};
