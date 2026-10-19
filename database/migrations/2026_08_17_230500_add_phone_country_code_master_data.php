<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_records')) return;

        $workspaceIds = Schema::hasTable('workspaces')
            ? DB::table('workspaces')->pluck('id')
            : collect([1]);

        $codes = [
            ['code' => 'PNC-001', 'name' => '+1',   'description' => 'United States / Canada', 'sort_order' => 10],
            ['code' => 'PNC-002', 'name' => '+44',  'description' => 'United Kingdom', 'sort_order' => 20],
            ['code' => 'PNC-003', 'name' => '+86',  'description' => 'China', 'sort_order' => 30],
            ['code' => 'PNC-004', 'name' => '+61',  'description' => 'Australia', 'sort_order' => 40],
            ['code' => 'PNC-005', 'name' => '+880', 'description' => 'Bangladesh', 'sort_order' => 50],
            ['code' => 'PNC-006', 'name' => '+91',  'description' => 'India', 'sort_order' => 60],
            ['code' => 'PNC-007', 'name' => '+81',  'description' => 'Japan', 'sort_order' => 70],
            ['code' => 'PNC-008', 'name' => '+82',  'description' => 'South Korea', 'sort_order' => 80],
            ['code' => 'PNC-009', 'name' => '+65',  'description' => 'Singapore', 'sort_order' => 90],
            ['code' => 'PNC-010', 'name' => '+971', 'description' => 'United Arab Emirates', 'sort_order' => 100],
            ['code' => 'PNC-011', 'name' => '+33',  'description' => 'France', 'sort_order' => 110],
            ['code' => 'PNC-012', 'name' => '+49',  'description' => 'Germany', 'sort_order' => 120],
            ['code' => 'PNC-013', 'name' => '+39',  'description' => 'Italy', 'sort_order' => 130],
            ['code' => 'PNC-014', 'name' => '+34',  'description' => 'Spain', 'sort_order' => 140],
            ['code' => 'PNC-015', 'name' => '+31',  'description' => 'Netherlands', 'sort_order' => 150],
        ];

        foreach ($workspaceIds as $workspaceId) {
            foreach ($codes as $row) {
                DB::table('master_records')->updateOrInsert(
                    [
                        'workspace_id' => (int) $workspaceId,
                        'type' => 'phone_country_code',
                        'code' => $row['code'],
                    ],
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
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_records')) return;

        DB::table('master_records')
            ->where('type', 'phone_country_code')
            ->whereIn('code', [
                'PNC-001', 'PNC-002', 'PNC-003', 'PNC-004', 'PNC-005',
                'PNC-006', 'PNC-007', 'PNC-008', 'PNC-009', 'PNC-010',
                'PNC-011', 'PNC-012', 'PNC-013', 'PNC-014', 'PNC-015',
            ])
            ->delete();
    }
};
