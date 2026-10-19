<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_module_access')) {
            return;
        }

        $catalogueModules = ['catalog_products', 'product_categories', 'suppliers'];
        $supportedActions = ['view', 'create', 'edit_own', 'edit_all', 'delete', 'assign', 'link', 'export', 'manage'];

        foreach (DB::table('roles')->orderBy('id')->get(['id']) as $role) {
            $source = DB::table('role_module_access')
                ->where('role_id', $role->id)
                ->where('module_code', 'masterdata')
                ->first(['actions']);

            $actions = is_array($source?->actions)
                ? $source->actions
                : json_decode((string) ($source?->actions ?? '[]'), true);
            $actions = is_array($actions)
                ? array_values(array_intersect($supportedActions, array_unique($actions)))
                : [];

            foreach ($catalogueModules as $module) {
                if (DB::table('role_module_access')
                    ->where('role_id', $role->id)
                    ->where('module_code', $module)
                    ->exists()) {
                    continue;
                }

                DB::table('role_module_access')->insert([
                    'role_id' => $role->id,
                    'module_code' => $module,
                    'record_scope' => $actions ? 'all_records' : 'none',
                    'actions' => json_encode($actions),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_module_access')) {
            return;
        }

        DB::table('role_module_access')
            ->whereIn('module_code', ['catalog_products', 'product_categories', 'suppliers'])
            ->delete();
    }
};
