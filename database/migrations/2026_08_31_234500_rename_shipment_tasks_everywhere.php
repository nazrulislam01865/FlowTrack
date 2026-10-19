<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the three Shipment task names consistent in Task Pack Setup,
     * workflow templates/snapshots, existing Orders and legacy mirrors.
     */
    public function up(): void
    {
        $namesByAutomationKey = [
            'SHIP_CONFIRM_INFO' => 'Review or update shipment details',
            'SHIP_LABEL' => 'Add tracking number & print courier label',
            'SHIP_PACKAGE' => 'Dispatch shipment',
        ];

        $legacyNamesByAutomationKey = [
            'SHIP_CONFIRM_INFO' => [
                'Confirm Shipment Information',
                'Confirm shipping information',
                'Review Shipment Info',
                'Review shipment information',
            ],
            'SHIP_LABEL' => [
                'Generate & Print Courier Label',
                'Generate Courier Label',
                'Preview & Print Courier Label',
            ],
            'SHIP_PACKAGE' => [
                'Ship Package',
                'Ship package',
                'Ship the Package',
            ],
        ];

        $shipmentPhaseIds = collect();
        $shipmentPackIds = collect();

        if (Schema::hasTable('workflow_phases')) {
            $shipmentPhases = DB::table('workflow_phases')
                ->where(function ($query): void {
                    $query->whereRaw('LOWER(name) = ?', ['shipment'])
                        ->orWhereRaw('LOWER(short_name) = ?', ['shipment']);
                })
                ->get(['id', 'task_pack_id']);

            $shipmentPhaseIds = $shipmentPhases->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();
            $shipmentPackIds = $shipmentPhases->pluck('task_pack_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        }

        if (Schema::hasTable('task_packs')) {
            $namedShipmentPackIds = DB::table('task_packs')
                ->where(function ($query): void {
                    $query->whereRaw('LOWER(name) = ?', ['shipment'])
                        ->orWhereRaw('LOWER(slug) = ?', ['shipment']);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            $shipmentPackIds = $shipmentPackIds->merge($namedShipmentPackIds)->unique()->values();
        }

        $templateIdsByKey = collect();

        if (Schema::hasTable('task_pack_items')) {
            foreach ($namesByAutomationKey as $automationKey => $newTitle) {
                $query = DB::table('task_pack_items');

                if (Schema::hasColumn('task_pack_items', 'automation_key')) {
                    $query->where(function ($where) use ($automationKey, $legacyNamesByAutomationKey, $shipmentPackIds): void {
                        $where->where('automation_key', $automationKey);

                        if ($shipmentPackIds->isNotEmpty()) {
                            $where->orWhere(function ($fallback) use ($legacyNamesByAutomationKey, $automationKey, $shipmentPackIds): void {
                                $fallback->whereIn('task_pack_id', $shipmentPackIds)
                                    ->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                            });
                        }
                    });
                } elseif ($shipmentPackIds->isNotEmpty()) {
                    $query->whereIn('task_pack_id', $shipmentPackIds)
                        ->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                } else {
                    $query->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                }

                $ids = (clone $query)->pluck('id')->map(fn ($id) => (int) $id);
                $templateIdsByKey->put($automationKey, $ids);

                if ($ids->isNotEmpty()) {
                    DB::table('task_pack_items')
                        ->whereIn('id', $ids)
                        ->update(['title' => $newTitle, 'updated_at' => now()]);
                }
            }
        }

        // task_pack_tasks is the compatibility mirror still read by older code.
        if (Schema::hasTable('task_pack_tasks')) {
            foreach ($namesByAutomationKey as $automationKey => $newTitle) {
                $ids = $templateIdsByKey->get($automationKey, collect());

                $query = DB::table('task_pack_tasks');
                if ($ids->isNotEmpty()) {
                    $query->whereIn('id', $ids);
                } elseif ($shipmentPackIds->isNotEmpty()) {
                    $query->whereIn('task_pack_id', $shipmentPackIds)
                        ->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                } else {
                    $query->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                }

                $query->update(['title' => $newTitle, 'updated_at' => now()]);
            }
        }

        // Existing Orders store a title snapshot in tasks, so update those too.
        if (Schema::hasTable('tasks')) {
            foreach ($namesByAutomationKey as $automationKey => $newTitle) {
                $ids = $templateIdsByKey->get($automationKey, collect());

                DB::table('tasks')
                    ->where(function ($query) use ($ids, $shipmentPhaseIds, $legacyNamesByAutomationKey, $automationKey): void {
                        if ($ids->isNotEmpty()) {
                            $query->whereIn('task_pack_task_id', $ids);
                        }

                        if ($shipmentPhaseIds->isNotEmpty()) {
                            $method = $ids->isNotEmpty() ? 'orWhere' : 'where';
                            $query->{$method}(function ($shipmentTasks) use ($shipmentPhaseIds, $legacyNamesByAutomationKey, $automationKey): void {
                                $shipmentTasks->whereIn('workflow_phase_id', $shipmentPhaseIds)
                                    ->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                            });
                        } elseif ($ids->isEmpty()) {
                            $query->whereIn('title', $legacyNamesByAutomationKey[$automationKey]);
                        }
                    })
                    ->update(['title' => $newTitle, 'updated_at' => now()]);
            }
        }
    }

    /**
     * This is an intentional canonical-data rename. Rolling it back would
     * overwrite any later administrator edits, so the down migration is a no-op.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
