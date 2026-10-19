<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'PROD_SET_ESTIMATED_DELIVERY';

    public function up(): void
    {
        if (! Schema::hasTable('task_pack_items') || ! Schema::hasTable('task_pack_tasks')) {
            return;
        }

        $packIds = DB::table('task_pack_items')
            ->when(
                Schema::hasColumn('task_pack_items', 'automation_key'),
                fn ($query) => $query->where('automation_key', self::KEY),
                fn ($query) => $query->whereRaw('LOWER(title) = ?', ['set estimated delivery date'])
            )
            ->pluck('task_pack_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($packIds as $packId) {
            DB::transaction(function () use ($packId): void {
                $items = DB::table('task_pack_items')
                    ->where('task_pack_id', $packId)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                if ($items->isEmpty()) {
                    return;
                }

                // Runtime tasks.task_pack_task_id is constrained to
                // task_pack_tasks.id. Older direct SQL insertion created the
                // modern item only, so temporarily move legacy sequences away
                // and rebuild same-id mirrors in canonical item order.
                DB::table('task_pack_tasks')
                    ->where('task_pack_id', $packId)
                    ->update(['sequence' => DB::raw('sequence + 30000')]);

                foreach ($items->values() as $index => $item) {
                    $payload = [
                        'task_pack_id' => $packId,
                        'title' => $item->title,
                        'sequence' => $index + 1,
                        'is_required' => (bool) $item->is_required,
                        'default_department_id' => $this->legacyDepartmentId($item->default_department_id ?? null),
                        'updated_at' => now(),
                    ];

                    if (Schema::hasColumn('task_pack_tasks', 'color')) {
                        $payload['color'] = $item->color ?: '#2563EB';
                    }

                    if (DB::table('task_pack_tasks')->where('id', $item->id)->exists()) {
                        DB::table('task_pack_tasks')->where('id', $item->id)->update($payload);
                    } else {
                        DB::table('task_pack_tasks')->insert($payload + [
                            'id' => $item->id,
                            'created_at' => $item->created_at ?? now(),
                        ]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Data-integrity repair only. Do not delete legacy mirror rows because
        // existing tasks may already reference them through the foreign key.
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
};
