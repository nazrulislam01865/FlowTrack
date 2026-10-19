<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_records') || ! Schema::hasTable('workspaces')) {
            return;
        }

        $defaults = [
            ['COU-001', 'UPS'],
            ['COU-002', 'FedEx'],
            ['COU-003', 'DHL'],
            ['COU-004', 'Other'],
        ];

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            $workspaceId = (int) $workspaceId;

            // Courier values become normal user-managed Master Data after this
            // seed. Never overwrite a workspace that has already configured it.
            if (DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', 'courier')
                ->whereNull('deleted_at')
                ->exists()) {
                continue;
            }

            $now = now();
            foreach ($defaults as $index => [$code, $name]) {
                DB::table('master_records')->insert([
                    'workspace_id' => $workspaceId,
                    'parent_id' => null,
                    'type' => 'courier',
                    'code' => $code,
                    'name' => $name,
                    'description' => 'Available when recording shipment tracking details.',
                    'metadata' => json_encode(['seeded_by' => 'courier_master_data_v1']),
                    'status' => 'active',
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally keep courier records: they are user-managed Master Data
        // as soon as the migration runs and may have been edited or extended.
    }
};
