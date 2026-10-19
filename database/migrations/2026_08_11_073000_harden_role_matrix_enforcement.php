<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->where('default_scope', 'selected_clients')
                ->update(['default_scope' => 'assigned_jobs', 'updated_at' => now()]);
        }

        if (! Schema::hasTable('role_module_access')) return;

        $supported = [
            'dashboard' => ['view'],
            'notifications' => ['view'],
            'clients' => ['view','create','edit_own','edit_all','delete','assign'],
            'inquiries' => ['view','create','edit_own','edit_all','delete','assign'],
            'jobs' => ['view','create','edit_own','edit_all','delete','assign','link'],
            'tasks' => ['view','create','edit_own','edit_all','delete','assign'],
            'documents' => ['view','create','delete','link','export'],
            'workflow' => ['manage'],
            'masterdata' => ['manage'],
        ];
        $universal = ['dashboard', 'notifications', 'clients', 'workflow', 'masterdata'];

        DB::table('role_module_access')
            ->whereIn('module_code', array_keys($supported))
            ->orderBy('id')
            ->get(['id', 'module_code', 'record_scope', 'actions'])
            ->each(function ($row) use ($supported, $universal): void {
                $actions = is_array($row->actions)
                    ? $row->actions
                    : json_decode((string) ($row->actions ?? '[]'), true);
                $actions = is_array($actions) ? $actions : [];
                $actions = array_values(array_unique(array_filter(
                    $actions,
                    fn ($action) => in_array($action, $supported[$row->module_code] ?? [], true),
                )));

                if (in_array('view', $supported[$row->module_code] ?? [], true)
                    && count(array_diff($actions, ['view'])) > 0
                    && ! in_array('view', $actions, true)) {
                    array_unshift($actions, 'view');
                }

                $scope = (string) ($row->record_scope ?: 'none');
                if ($scope === 'selected_clients') $scope = 'assigned_jobs';
                if (in_array($row->module_code, $universal, true)) {
                    $scope = $actions ? 'all_records' : 'none';
                }

                DB::table('role_module_access')->where('id', $row->id)->update([
                    'actions' => json_encode(array_values($actions)),
                    'record_scope' => $scope,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Permission normalization is intentionally not reversed because the
        // removed cells never had an enforceable application behavior.
    }
};
