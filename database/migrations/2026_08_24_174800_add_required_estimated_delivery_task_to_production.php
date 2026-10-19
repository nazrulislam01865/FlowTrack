<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'PROD_SET_ESTIMATED_DELIVERY';

    public function up(): void
    {
        if (! Schema::hasTable('workflow_templates')
            || ! Schema::hasTable('workflow_phases')
            || ! Schema::hasTable('task_packs')
            || ! Schema::hasTable('task_pack_items')) {
            return;
        }

        $workflowIds = DB::table('workflow_templates')
            ->where('applies_to', 'orders')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($workflowIds === []) {
            return;
        }

        $productionPhases = DB::table('workflow_phases')
            ->whereIn('workflow_template_id', $workflowIds)
            ->whereNotNull('task_pack_id')
            ->where(function ($query): void {
                $query->where('sequence', 3)
                    ->orWhereRaw('LOWER(name) = ?', ['production']);
            })
            ->get(['id', 'workflow_template_id', 'task_pack_id']);

        foreach ($productionPhases as $phase) {
            $packId = (int) $phase->task_pack_id;
            if ($packId <= 0) {
                continue;
            }

            $exists = DB::table('task_pack_items')
                ->where('task_pack_id', $packId)
                ->where('automation_key', self::KEY)
                ->exists();

            if ($exists) {
                continue;
            }

            $startTask = DB::table('task_pack_items')
                ->where('task_pack_id', $packId)
                ->where(function ($query): void {
                    $query->where('automation_key', 'PROD_START')
                        ->orWhereRaw('LOWER(title) = ?', ['start production']);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            $insertAt = max(0, (int) ($startTask->sort_order ?? 0));

            DB::table('task_pack_items')
                ->where('task_pack_id', $packId)
                ->where('sort_order', '>=', $insertAt)
                ->increment('sort_order');

            $values = [
                'task_pack_id' => $packId,
                'title' => 'Set estimated delivery date',
                'description' => 'Required before Production can start.',
                'default_assignee_id' => $startTask->default_assignee_id ?? null,
                'default_department_id' => $startTask->default_department_id ?? null,
                'priority_id' => $startTask->priority_id ?? null,
                'document_category_id' => null,
                'due_offset_days' => 0,
                'is_required' => true,
                'sort_order' => $insertAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('task_pack_items', 'automation_key')) {
                $values['automation_key'] = self::KEY;
            }
            if (Schema::hasColumn('task_pack_items', 'color')) {
                $values['color'] = '#f28c28';
            }
            if (Schema::hasColumn('task_pack_items', 'document_required_before_completion')) {
                $values['document_required_before_completion'] = false;
            }
            if (Schema::hasColumn('task_pack_items', 'allow_multiple_documents')) {
                $values['allow_multiple_documents'] = false;
            }
            if (Schema::hasColumn('task_pack_items', 'document_instructions')) {
                $values['document_instructions'] = null;
            }

            DB::table('task_pack_items')->insert($values);

            // IMPORTANT: runtime tasks.task_pack_task_id references the legacy
            // task_pack_tasks table. Keep both Task Pack representations on
            // the same primary keys, exactly like TaskPackService::saveItem().
            $this->mirrorPackItemsToLegacy($packId);
        }
    }

    private function mirrorPackItemsToLegacy(int $packId): void
    {
        if (! Schema::hasTable('task_pack_tasks')) {
            return;
        }

        $items = DB::table('task_pack_items')
            ->where('task_pack_id', $packId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        // Free the canonical 1..N sequence range first. The legacy table has
        // a unique(task_pack_id, sequence) constraint.
        DB::table('task_pack_tasks')
            ->where('task_pack_id', $packId)
            ->update(['sequence' => DB::raw('sequence + 30000')]);

        foreach ($items->values() as $index => $item) {
            $departmentId = $this->legacyDepartmentId($item->default_department_id ?? null);
            $payload = [
                'task_pack_id' => $packId,
                'title' => $item->title,
                'sequence' => $index + 1,
                'is_required' => (bool) $item->is_required,
                'default_department_id' => $departmentId,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('task_pack_tasks', 'color')) {
                $payload['color'] = $item->color ?: '#2563EB';
            }

            $exists = DB::table('task_pack_tasks')->where('id', $item->id)->exists();
            if ($exists) {
                DB::table('task_pack_tasks')->where('id', $item->id)->update($payload);
            } else {
                DB::table('task_pack_tasks')->insert($payload + [
                    'id' => $item->id,
                    'created_at' => $item->created_at ?? now(),
                ]);
            }
        }
    }

    private function legacyDepartmentId(mixed $masterDepartmentId): ?int
    {
        if (! $masterDepartmentId
            || ! Schema::hasTable('master_records')
            || ! Schema::hasTable('departments')) {
            return null;
        }

        $code = DB::table('master_records')->where('id', $masterDepartmentId)->value('code');
        if (! $code) {
            return null;
        }

        $id = DB::table('departments')->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_pack_items')) {
            return;
        }

        $itemIds = DB::table('task_pack_items')
            ->where('automation_key', self::KEY)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($itemIds === []) {
            return;
        }

        if (Schema::hasTable('tasks')) {
            DB::table('tasks')->whereIn('task_pack_task_id', $itemIds)->delete();
        }

        DB::table('task_pack_items')->whereIn('id', $itemIds)->delete();
    }
};
