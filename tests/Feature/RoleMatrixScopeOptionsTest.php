<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\AdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMatrixScopeOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_permission_matrix_module_accepts_the_same_five_scope_options(): void
    {
        foreach (array_keys(AccessControlService::MODULES) as $module) {
            $this->assertTrue(AccessControlService::supportsScope($module), $module.' should expose record scope.');
        }

        $this->assertSame(
            ['none', 'own_records', 'assigned_jobs', 'department', 'all_records'],
            AccessControlService::RECORD_SCOPES,
        );
    }

    public function test_shared_module_scope_selection_is_persisted_and_not_reset_by_permission_changes(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $role = Role::create([
            'workspace_id' => 1,
            'name' => 'Scope Matrix '.uniqid(),
            'slug' => 'scope-matrix-'.uniqid(),
            'code' => 'SCOPE_'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'description' => 'Scope matrix regression role',
            'default_scope' => 'assigned_jobs',
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);

        RoleModuleAccess::create([
            'role_id' => $role->id,
            'module_code' => 'clients',
            'record_scope' => 'all_records',
            'actions' => ['view'],
        ]);

        $service = app(AdminService::class);
        $service->setScope($role, 'clients', 'department', $admin);
        $service->setMatrixAction($role, 'clients', 'create', true, $admin);

        $row = RoleModuleAccess::where('role_id', $role->id)->where('module_code', 'clients')->firstOrFail();
        $this->assertSame('department', $row->record_scope);
        $this->assertContains('view', $row->actions);
        $this->assertContains('create', $row->actions);
    }

    public function test_none_scope_clears_actions_for_any_module(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $role = Role::create([
            'workspace_id' => 1,
            'name' => 'No Scope '.uniqid(),
            'slug' => 'no-scope-'.uniqid(),
            'code' => 'NONE_'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'description' => 'Scope matrix regression role',
            'default_scope' => 'assigned_jobs',
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);

        RoleModuleAccess::create([
            'role_id' => $role->id,
            'module_code' => 'masterdata',
            'record_scope' => 'all_records',
            'actions' => ['view', 'edit_own'],
        ]);

        app(AdminService::class)->setScope($role, 'masterdata', 'none', $admin);

        $row = RoleModuleAccess::where('role_id', $role->id)->where('module_code', 'masterdata')->firstOrFail();
        $this->assertSame('none', $row->record_scope);
        $this->assertSame([], $row->actions);
    }
}
