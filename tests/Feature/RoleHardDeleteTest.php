<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\AdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleHardDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_permanently_delete_role_without_deleting_users(): void
    {
        $actor = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $replacement = $this->role('Replacement');
        $target = $this->role('Delete Me');

        RoleModuleAccess::create([
            'role_id' => $target->id,
            'module_code' => 'jobs',
            'record_scope' => 'assigned_jobs',
            'actions' => ['view'],
        ]);

        $onlyTarget = User::factory()->create(['role_id' => $target->id, 'is_active' => true]);
        $onlyTarget->roles()->sync([$target->id]);
        WorkspaceMembership::updateOrCreate(
            ['workspace_id' => 1, 'user_id' => $onlyTarget->id],
            ['role_id' => $target->id, 'status' => 'active', 'joined_at' => now()],
        );

        $multiRole = User::factory()->create(['role_id' => $target->id, 'is_active' => true]);
        $multiRole->roles()->sync([$target->id, $replacement->id]);
        WorkspaceMembership::updateOrCreate(
            ['workspace_id' => 1, 'user_id' => $multiRole->id],
            ['role_id' => $target->id, 'status' => 'active', 'joined_at' => now()],
        );

        $affected = app(AdminService::class)->deleteRole($target, $actor);

        $this->assertSame(2, $affected);
        $this->assertDatabaseMissing('roles', ['id' => $target->id]);
        $this->assertDatabaseMissing('role_module_access', ['role_id' => $target->id]);
        $this->assertDatabaseMissing('user_roles', ['role_id' => $target->id]);

        $this->assertDatabaseHas('users', ['id' => $onlyTarget->id, 'role_id' => null]);
        $this->assertDatabaseHas('workspace_memberships', ['user_id' => $onlyTarget->id, 'role_id' => null]);

        $this->assertDatabaseHas('users', ['id' => $multiRole->id, 'role_id' => $replacement->id]);
        $this->assertDatabaseHas('workspace_memberships', ['user_id' => $multiRole->id, 'role_id' => $replacement->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $multiRole->id, 'role_id' => $replacement->id]);
    }

    public function test_non_administrator_cannot_delete_role(): void
    {
        $actor = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
        $target = $this->role('Protected From Normal User');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(AdminService::class)->deleteRole($target, $actor);
    }

    private function role(string $name): Role
    {
        return Role::create([
            'workspace_id' => 1,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'code' => strtoupper(str_replace(' ', '_', $name)).'_'.strtoupper(substr(md5((string) microtime(true)), 0, 6)),
            'description' => 'Role deletion regression test',
            'default_scope' => 'assigned_jobs',
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);
    }
}
