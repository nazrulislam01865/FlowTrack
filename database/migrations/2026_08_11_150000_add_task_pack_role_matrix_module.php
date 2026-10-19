<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_module_access')) return;

        foreach (DB::table('roles')->select(['id', 'slug'])->get() as $role) {
            $existing = DB::table('role_module_access')
                ->where('role_id', $role->id)
                ->where('module_code', 'taskpacks')
                ->exists();

            if ($existing) continue;

            $workflow = DB::table('role_module_access')
                ->where('role_id', $role->id)
                ->where('module_code', 'workflow')
                ->first(['actions']);

            $workflowActions = $workflow?->actions ? json_decode($workflow->actions, true) : [];
            $hadWorkflowManage = in_array('manage', is_array($workflowActions) ? $workflowActions : [], true);
            $isAdministrator = in_array((string) $role->slug, ['super-admin', 'admin', 'administrator'], true);
            $actions = ($hadWorkflowManage || $isAdministrator)
                ? ['view', 'create', 'edit_all', 'delete', 'manage']
                : [];

            DB::table('role_module_access')->insert([
                'role_id' => $role->id,
                'module_code' => 'taskpacks',
                'record_scope' => $actions ? 'all_records' : 'none',
                'actions' => json_encode($actions),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('role_module_access')) return;
        DB::table('role_module_access')->where('module_code', 'taskpacks')->delete();
    }
};
