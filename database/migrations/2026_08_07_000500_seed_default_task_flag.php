<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records') || !Schema::hasTable('workspaces')) return;

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            $exists = DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'task_flag')
                ->where('code', 'MANAGEMENT')
                ->exists();

            if ($exists) continue;

            DB::table('master_records')->insert([
                'workspace_id' => $workspaceId,
                'parent_id' => null,
                'type' => 'task_flag',
                'code' => 'MANAGEMENT',
                'name' => 'Management attention',
                'description' => 'Flags a task for management attention.',
                'metadata' => null,
                'status' => 'active',
                'sort_order' => 10,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('master_records')) return;

        DB::table('master_records')
            ->where('type', 'task_flag')
            ->where('code', 'MANAGEMENT')
            ->delete();
    }
};
