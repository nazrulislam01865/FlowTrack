<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_jobs') && !Schema::hasColumn('flow_jobs', 'notes')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('description');
            });
        }

        if (!Schema::hasTable('master_records')) return;

        $workspaceIds = Schema::hasTable('workspaces')
            ? DB::table('workspaces')->pluck('id')
            : collect([1]);

        foreach ($workspaceIds as $workspaceId) {
            foreach ([
                ['type' => 'production_urgency', 'code' => 'PUR-002', 'name' => 'Super Urgent', 'description' => 'Order requires the highest production urgency.'],
                ['type' => 'shipment_urgency', 'code' => 'SUR-002', 'name' => 'Super Urgent', 'description' => 'Order requires the highest shipment urgency.'],
            ] as $definition) {
                $existing = DB::table('master_records')
                    ->where('workspace_id', (int) $workspaceId)
                    ->where('type', $definition['type'])
                    ->where(function ($query) use ($definition): void {
                        $query->where('code', $definition['code'])
                            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($definition['name'])]);
                    })
                    ->first();

                if ($existing) {
                    DB::table('master_records')->where('id', $existing->id)->update([
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'status' => 'active',
                        'deleted_at' => null,
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                DB::table('master_records')->insert([
                    'workspace_id' => (int) $workspaceId,
                    'type' => $definition['type'],
                    'parent_id' => null,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'metadata' => null,
                    'status' => 'active',
                    'sort_order' => 20,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('master_records')) {
            DB::table('master_records')
                ->whereIn('type', ['production_urgency', 'shipment_urgency'])
                ->whereIn('code', ['PUR-002', 'SUR-002'])
                ->delete();
        }

        if (Schema::hasTable('flow_jobs') && Schema::hasColumn('flow_jobs', 'notes')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }
    }
};
