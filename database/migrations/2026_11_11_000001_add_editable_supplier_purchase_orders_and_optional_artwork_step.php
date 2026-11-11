<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->date('po_delivery_date')->nullable()->after('template_details');
            $table->unsignedInteger('revision')->default(1)->after('po_delivery_date');
        });

        if (!Schema::hasTable('workflow_phases') || !Schema::hasTable('task_pack_items')) return;

        $packIds = DB::table('workflow_phases as phase')
            ->join('workflow_templates as flow', 'flow.id', '=', 'phase.workflow_template_id')
            ->where('flow.applies_to', 'orders')
            ->where('phase.sequence', 2)
            ->whereNotNull('phase.task_pack_id')
            ->distinct()->pluck('phase.task_pack_id');

        foreach ($packIds as $packId) {
            DB::transaction(function () use ($packId): void {
                $items = DB::table('task_pack_items')->where('task_pack_id', $packId)
                    ->orderBy('sort_order')->orderBy('id')->get();
                if ($items->contains(fn ($item) => $item->automation_key === 'ART_SUPPLIER_PO_OPTIONAL')) return;

                $sample = $items->first(fn ($item) => $item->automation_key === 'ART_SAMPLE_APPROVAL');
                if (!$sample) return;

                $position = (int) $sample->sort_order;
                DB::table('task_pack_items')->where('task_pack_id', $packId)
                    ->where('sort_order', '>', $position)->increment('sort_order');

                DB::table('task_pack_items')->insert([
                    'task_pack_id' => $packId,
                    'automation_key' => 'ART_SUPPLIER_PO_OPTIONAL',
                    'title' => 'Create Supplier Purchase Order (optional)',
                    'description' => 'Create a supplier purchase order after artwork approval and any sample handoff, or skip this optional step.',
                    'default_department_id' => $sample->default_department_id,
                    'default_assignee_id' => null,
                    'priority_id' => $sample->priority_id,
                    'due_offset_days' => 0,
                    'is_required' => false,
                    'sort_order' => $position + 1,
                    'document_required_before_completion' => false,
                    'allow_multiple_documents' => false,
                    'color' => '#7b61c9',
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                // tasks.task_pack_task_id references the legacy mirror, not
                // task_pack_items; reserve the unique sequence range first.
                if (Schema::hasTable('task_pack_tasks')) {
                    DB::table('task_pack_tasks')->where('task_pack_id', $packId)
                        ->update(['sequence' => DB::raw('sequence + 30000')]);
                    $ordered = DB::table('task_pack_items')->where('task_pack_id', $packId)
                        ->orderBy('sort_order')->orderBy('id')->get();
                    foreach ($ordered->values() as $index => $item) {
                        $departmentId = null;
                        if ($item->default_department_id && Schema::hasTable('departments')) {
                            $code = DB::table('master_records')->where('id', $item->default_department_id)->value('code');
                            if ($code) $departmentId = DB::table('departments')->where('code', $code)->value('id');
                        }
                        $mirror = [
                            'task_pack_id' => $packId,
                            'title' => $item->title,
                            'sequence' => $index + 1,
                            'is_required' => (bool) $item->is_required,
                            'default_department_id' => $departmentId,
                            'updated_at' => now(),
                        ];
                        if (Schema::hasColumn('task_pack_tasks', 'color')) {
                            $mirror['color'] = $item->color ?: '#2563EB';
                        }
                        if (DB::table('task_pack_tasks')->where('id', $item->id)->exists()) {
                            DB::table('task_pack_tasks')->where('id', $item->id)->update($mirror);
                        } else {
                            DB::table('task_pack_tasks')->insert($mirror + [
                                'id' => $item->id, 'created_at' => now(),
                            ]);
                        }
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Retain historic task definitions and their audit references. Rolling
        // back the new data fields is deliberately non-destructive as well.
    }
};
