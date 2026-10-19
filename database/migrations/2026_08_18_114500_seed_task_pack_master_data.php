<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workspaces') || !Schema::hasTable('master_records')) return;

        $definitions = [
            'task_pack_duration_unit' => [
                ['code' => 'TPD-001', 'name' => 'Business hours'],
                ['code' => 'TPD-002', 'name' => 'Calendar hours'],
                ['code' => 'TPD-003', 'name' => 'Business days'],
                ['code' => 'TPD-004', 'name' => 'Calendar days'],
            ],
            'task_pack_timer_start' => [
                ['code' => 'TPS-001', 'name' => 'When status changes to In Progress'],
            ],
            'task_pack_timer_stop' => [
                ['code' => 'TPE-001', 'name' => 'When status changes to Completed'],
            ],
            'task_pack_work_calendar' => [
                ['code' => 'TPW-001', 'name' => 'Workspace hours · Mon–Fri, 9:00–18:00'],
            ],
            'task_pack_pause_status' => [
                ['code' => 'TPP-001', 'name' => 'Waiting for Client'],
                ['code' => 'TPP-002', 'name' => 'Waiting for Supplier'],
                ['code' => 'TPP-003', 'name' => 'Waiting for Approval'],
            ],
        ];

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            foreach ($definitions as $type => $records) {
                foreach ($records as $index => $record) {
                    DB::table('master_records')->updateOrInsert(
                        ['workspace_id' => $workspaceId, 'type' => $type, 'code' => $record['code']],
                        [
                            'parent_id' => null,
                            'name' => $record['name'],
                            'description' => null,
                            'metadata' => json_encode(['seeded_by' => 'task_pack_master_data_v1'], JSON_UNESCAPED_UNICODE),
                            'status' => 'active',
                            'sort_order' => $index + 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                            'deleted_at' => null,
                        ]
                    );
                }
            }

            if (!Schema::hasTable('task_packs') || !Schema::hasTable('task_pack_items')) continue;

            $packIds = DB::table('task_packs')->where('workspace_id', $workspaceId)->pluck('id');
            if ($packIds->isEmpty()) continue;

            if (Schema::hasColumn('task_pack_items', 'standard_duration_unit')) {
                $durationMap = [
                    'business_hours' => 'TPD-001',
                    'calendar_hours' => 'TPD-002',
                    'business_days' => 'TPD-003',
                    'calendar_days' => 'TPD-004',
                ];
                foreach ($durationMap as $legacy => $code) {
                    DB::table('task_pack_items')->whereIn('task_pack_id', $packIds)->where('standard_duration_unit', $legacy)->update(['standard_duration_unit' => $code]);
                }
            }

            if (Schema::hasColumn('task_pack_items', 'timer_start_rule')) {
                DB::table('task_pack_items')->whereIn('task_pack_id', $packIds)->where('timer_start_rule', 'status_in_progress')->update(['timer_start_rule' => 'TPS-001']);
            }
            if (Schema::hasColumn('task_pack_items', 'timer_stop_rule')) {
                DB::table('task_pack_items')->whereIn('task_pack_id', $packIds)->where('timer_stop_rule', 'status_completed')->update(['timer_stop_rule' => 'TPE-001']);
            }
            if (Schema::hasColumn('task_pack_items', 'work_calendar')) {
                DB::table('task_pack_items')->whereIn('task_pack_id', $packIds)->where('work_calendar', 'workspace_hours')->update(['work_calendar' => 'TPW-001']);
            }
            if (Schema::hasColumn('task_pack_items', 'pause_statuses')) {
                $pauseMap = [
                    'Waiting for Client' => 'TPP-001',
                    'Waiting for Supplier' => 'TPP-002',
                    'Waiting for Approval' => 'TPP-003',
                ];
                DB::table('task_pack_items')
                    ->whereIn('task_pack_id', $packIds)
                    ->whereNotNull('pause_statuses')
                    ->get(['id', 'pause_statuses'])
                    ->each(function ($row) use ($pauseMap): void {
                        $values = json_decode((string) $row->pause_statuses, true);
                        if (!is_array($values)) return;
                        $mapped = array_values(array_unique(array_map(fn ($value) => $pauseMap[$value] ?? $value, $values)));
                        DB::table('task_pack_items')->where('id', $row->id)->update(['pause_statuses' => json_encode($mapped, JSON_UNESCAPED_UNICODE)]);
                    });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('master_records')) return;
        DB::table('master_records')
            ->whereIn('type', [
                'task_pack_duration_unit',
                'task_pack_timer_start',
                'task_pack_timer_stop',
                'task_pack_work_calendar',
                'task_pack_pause_status',
            ])
            ->delete();
    }
};
