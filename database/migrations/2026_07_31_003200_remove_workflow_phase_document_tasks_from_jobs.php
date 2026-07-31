<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_tasks')
            || ! Schema::hasColumn('flow_tasks', 'document_requirement_source')
            || ! Schema::hasColumn('flow_tasks', 'deleted_at')) {
            return;
        }

        // Phase-level document requirements are displayed and uploaded from the
        // Documents panel. They must not create extra rows in "All phase tasks"
        // because that table is meant to match Task Pack Setup exactly.
        DB::table('flow_tasks')
            ->whereNull('task_pack_item_id')
            ->where('document_requirement_source', 'workflow_phase')
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data cleanup only. Do not restore hidden workflow-phase document tasks.
    }
};
