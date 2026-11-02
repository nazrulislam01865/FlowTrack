<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('workflow_phases')
            || !Schema::hasTable('workflow_templates')
            || !Schema::hasTable('task_pack_items')) {
            return;
        }

        // Both Order workflow variants use independently configured Artwork
        // packs. Move only their supplier PO item; preserve every other task's
        // relative ordering, existing completion history and document links.
        $packIds = DB::table('workflow_phases as phase')
            ->join('workflow_templates as flow', 'flow.id', '=', 'phase.workflow_template_id')
            ->where('flow.applies_to', 'orders')
            ->where('phase.sequence', 2)
            ->whereNotNull('phase.task_pack_id')
            ->distinct()
            ->pluck('phase.task_pack_id');

        foreach ($packIds as $packId) {
            DB::transaction(function () use ($packId): void {
                $items = DB::table('task_pack_items')
                    ->where('task_pack_id', $packId)
                    ->orderBy('sort_order')->orderBy('id')
                    ->get(['id', 'automation_key', 'sort_order']);
                $po = $items->first(fn ($item) => $item->automation_key === 'ART_CREATE_SUPPLIER_PO');
                if (!$po) return;

                if ((int) $items->last()->id !== (int) $po->id) {
                    DB::table('task_pack_items')->where('id', $po->id)
                        ->where('task_pack_id', $packId)
                        ->update(['sort_order' => (int) $items->max('sort_order') + 1, 'updated_at' => now()]);
                }

                // Older Task Pack consumers still read this compatibility
                // table, whose (task_pack_id, sequence) values are unique.
                if (!Schema::hasTable('task_pack_tasks')) return;
                $legacy = DB::table('task_pack_tasks')
                    ->where('task_pack_id', $packId)
                    ->orderBy('sequence')->orderBy('id')
                    ->get(['id', 'sequence']);
                if ($legacy->isEmpty()) return;
                $legacyPo = $legacy->first(fn ($item) => (int) $item->id === (int) $po->id);
                if ($legacyPo && (int) $legacy->last()->id !== (int) $po->id) {
                    DB::table('task_pack_tasks')->where('id', $po->id)
                        ->where('task_pack_id', $packId)
                        ->update(['sequence' => (int) $legacy->max('sequence') + 1, 'updated_at' => now()]);
                }
            });
        }
    }

    public function down(): void
    {
        // Do not infer a previous custom Task Pack order on rollback.
        // An administrator can explicitly reorder tasks in Workflow Setup.
    }
};
