<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pause is no longer a Task Pack setup/master-data rule. A future runtime
        // pause action will collect a reason from the user instead.
        if (Schema::hasTable('master_records')) {
            DB::table('master_records')->where('type', 'task_pack_pause_status')->delete();
        }

        if (Schema::hasTable('task_pack_items') && Schema::hasColumn('task_pack_items', 'pause_statuses')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->dropColumn('pause_statuses');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_pack_items') && !Schema::hasColumn('task_pack_items', 'pause_statuses')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->json('pause_statuses')->nullable()->after('timer_stop_rule');
            });
        }

        if (!Schema::hasTable('workspaces') || !Schema::hasTable('master_records')) return;

        $records = [
            ['code' => 'TPP-001', 'name' => 'Waiting for Client'],
            ['code' => 'TPP-002', 'name' => 'Waiting for Supplier'],
            ['code' => 'TPP-003', 'name' => 'Waiting for Approval'],
        ];

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            foreach ($records as $index => $record) {
                DB::table('master_records')->updateOrInsert(
                    ['workspace_id' => $workspaceId, 'type' => 'task_pack_pause_status', 'code' => $record['code']],
                    [
                        'parent_id' => null,
                        'name' => $record['name'],
                        'description' => null,
                        'metadata' => json_encode(['restored_by' => 'task_pack_pause_status_rollback'], JSON_UNESCAPED_UNICODE),
                        'status' => 'active',
                        'sort_order' => $index + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]
                );
            }
        }
    }
};
