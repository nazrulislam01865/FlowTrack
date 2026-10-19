<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                if (! Schema::hasColumn('flow_jobs', 'production_urgency_ids')) {
                    $table->json('production_urgency_ids')->nullable()->after('priority');
                }
                if (! Schema::hasColumn('flow_jobs', 'shipment_urgency_ids')) {
                    $table->json('shipment_urgency_ids')->nullable()->after('production_urgency_ids');
                }
            });
        }

        if (! Schema::hasTable('master_records')) return;

        $workspaceIds = Schema::hasTable('workspaces')
            ? DB::table('workspaces')->pluck('id')
            : collect([1]);

        foreach ($workspaceIds as $workspaceId) {
            $rows = [
                ['type' => 'production_urgency', 'code' => 'PUR-001', 'name' => 'Urgent', 'description' => 'Order requires production urgency.', 'sort_order' => 10],
                ['type' => 'shipment_urgency', 'code' => 'SUR-001', 'name' => 'Urgent', 'description' => 'Order requires shipment urgency.', 'sort_order' => 10],
            ];

            foreach ($rows as $row) {
                DB::table('master_records')->updateOrInsert(
                    ['workspace_id' => (int) $workspaceId, 'type' => $row['type'], 'code' => $row['code']],
                    [
                        'parent_id' => null,
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'metadata' => null,
                        'status' => 'active',
                        'sort_order' => $row['sort_order'],
                        'updated_at' => now(),
                        'deleted_at' => null,
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('master_records')) {
            DB::table('master_records')
                ->whereIn('type', ['production_urgency', 'shipment_urgency'])
                ->whereIn('code', ['PUR-001', 'SUR-001'])
                ->delete();
        }

        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                if (Schema::hasColumn('flow_jobs', 'shipment_urgency_ids')) $table->dropColumn('shipment_urgency_ids');
                if (Schema::hasColumn('flow_jobs', 'production_urgency_ids')) $table->dropColumn('production_urgency_ids');
            });
        }
    }
};
