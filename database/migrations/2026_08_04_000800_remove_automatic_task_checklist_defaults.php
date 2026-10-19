<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flow_task_checklist_items')) return;

        $systemDefaults = [
            0 => 'Confirm required information',
            1 => 'Upload supporting file',
            2 => 'Add completion comment',
            3 => 'Verify owner approval',
            4 => 'Close task handoff',
        ];

        $rows = DB::table('flow_task_checklist_items')
            ->whereIn('label', array_values($systemDefaults))
            ->orderBy('flow_task_id')
            ->orderBy('sort_order')
            ->get(['id', 'flow_task_id', 'sort_order', 'label'])
            ->groupBy('flow_task_id');

        foreach ($rows as $taskRows) {
            $matchedByOrder = $taskRows->filter(function ($row) use ($systemDefaults) {
                return array_key_exists((int) $row->sort_order, $systemDefaults)
                    && $systemDefaults[(int) $row->sort_order] === $row->label;
            });

            // The application previously injected the first three rows into every
            // generated task. Demo data used the same first three plus two more.
            // Remove only those recognized system defaults; user-created rows stay.
            $hasInjectedCore = collect([0, 1, 2])->every(function ($order) use ($matchedByOrder, $systemDefaults) {
                return $matchedByOrder->contains(fn ($row) =>
                    (int) $row->sort_order === $order && $row->label === $systemDefaults[$order]
                );
            });

            if ($hasInjectedCore) {
                DB::table('flow_task_checklist_items')->whereIn('id', $matchedByOrder->pluck('id'))->delete();
            }
        }
    }

    public function down(): void
    {
        // Intentionally not recreated. These rows were not Task Pack/user data.
    }
};
