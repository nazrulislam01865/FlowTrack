<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records') || !Schema::hasTable('workspaces')) return;

        $statuses = [
            ['code' => 'IST-001', 'name' => 'In Progress', 'description' => 'Inquiry is actively being worked.', 'sort_order' => 10],
            ['code' => 'IST-002', 'name' => 'Waiting for Client', 'description' => 'Waiting for client input or confirmation.', 'sort_order' => 20],
            ['code' => 'IST-003', 'name' => 'Waiting for Supplier', 'description' => 'Waiting for supplier input or confirmation.', 'sort_order' => 30],
            ['code' => 'IST-004', 'name' => 'On Hold', 'description' => 'Inquiry is temporarily paused.', 'sort_order' => 40],
        ];

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            foreach ($statuses as $status) {
                DB::table('master_records')->updateOrInsert(
                    [
                        'workspace_id' => $workspaceId,
                        'type' => 'inquiry_status',
                        'code' => $status['code'],
                    ],
                    [
                        'parent_id' => null,
                        'name' => $status['name'],
                        'description' => $status['description'],
                        'metadata' => null,
                        'status' => 'active',
                        'sort_order' => $status['sort_order'],
                        'deleted_at' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('master_records')) return;

        DB::table('master_records')
            ->where('type', 'inquiry_status')
            ->whereIn('code', ['IST-001', 'IST-002', 'IST-003', 'IST-004'])
            ->delete();
    }
};
