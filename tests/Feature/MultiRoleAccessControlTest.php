<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\FlowJob;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiRoleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_combined_across_multiple_roles(): void
    {
        $viewRole = $this->roleWith('jobs', 'assigned_jobs', ['view']);
        $financeRole = $this->roleWith('finance', 'all_records', ['view', 'create']);
        $user = User::factory()->create([
            'role_id' => $viewRole->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $user->roles()->sync([$viewRole->id, $financeRole->id]);

        $access = app(AccessControlService::class);

        $this->assertTrue($access->can($user, 'jobs', 'view'));
        $this->assertTrue($access->can($user, 'finance', 'view'));
        $this->assertTrue($access->can($user, 'finance', 'create'));
        $this->assertFalse($access->can($user, 'jobs', 'delete'));
    }

    public function test_record_scopes_from_multiple_roles_are_unioned(): void
    {
        $ownRole = $this->roleWith('jobs', 'own_records', ['view']);
        $departmentRole = $this->roleWith('jobs', 'department', ['view']);
        $department = Department::create(['code' => 'MR-'.uniqid(), 'name' => 'Multi Role Department '.uniqid(), 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $ownRole->id,
            'department_id' => $department->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $user->roles()->sync([$ownRole->id, $departmentRole->id]);

        $other = User::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        $client = Client::create(['name' => 'Scoped Client', 'code' => 'SCOPED-'.uniqid(), 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'Scoped Workflow', 'slug' => 'scoped-'.uniqid(), 'is_active' => true]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Start',
            'short_name' => 'Start',
            'allow_job_start' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'JOB-MULTI-SCOPE',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $other->id,
            'coordinator_id' => $other->id,
            'title' => 'Department job',
        ]);

        $this->assertContains('own_records', app(AccessControlService::class)->scopes($user, 'jobs'));
        $this->assertContains('department', app(AccessControlService::class)->scopes($user, 'jobs'));
        $this->assertTrue(app(JobService::class)->visibleQuery($user)->whereKey($job->id)->exists());
    }

    public function test_non_view_role_does_not_widen_record_visibility_scope(): void
    {
        $viewOwnRole = $this->roleWith('jobs', 'own_records', ['view']);
        $createOnlyRole = $this->roleWith('jobs', 'all_records', ['create']);
        $user = User::factory()->create([
            'role_id' => $viewOwnRole->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $user->roles()->sync([$viewOwnRole->id, $createOnlyRole->id]);

        $access = app(AccessControlService::class);
        $this->assertSame(['own_records'], $access->scopes($user, 'jobs'));
        $this->assertTrue($access->can($user, 'jobs', 'view'));
        $this->assertTrue($access->can($user, 'jobs', 'create'));
    }

    public function test_legacy_primary_role_change_keeps_pivot_in_sync_without_dropping_secondary_roles(): void
    {
        $primary = $this->roleWith('jobs', 'own_records', ['view']);
        $secondary = $this->roleWith('finance', 'all_records', ['view']);
        $replacement = $this->roleWith('documents', 'own_records', ['view']);
        $user = User::factory()->create([
            'role_id' => $primary->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $user->roles()->sync([$primary->id, $secondary->id]);

        // Simulate an older integration that still writes only users.role_id.
        $user->update(['role_id' => $replacement->id]);

        $this->assertEqualsCanonicalizing(
            [$secondary->id, $replacement->id],
            $user->fresh()->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_secondary_admin_role_grants_administrator_behavior(): void
    {
        $normalRole = $this->roleWith('jobs', 'assigned_jobs', ['view']);
        $adminRole = Role::query()->where('workspace_id', 1)->whereIn('slug', ['admin', 'administrator'])->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $normalRole->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $user->roles()->sync([$normalRole->id, $adminRole->id]);

        $this->assertTrue(app(AccessControlService::class)->isAdministrator($user));
        $this->assertTrue(app(AccessControlService::class)->can($user, 'masterdata', 'manage'));
    }

    private function roleWith(string $module, string $scope, array $actions): Role
    {
        $role = Role::create([
            'workspace_id' => 1,
            'name' => 'Multi Role '.uniqid(),
            'slug' => 'multi-role-'.uniqid(),
            'code' => 'MULTI_'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'description' => 'Multi-role test',
            'default_scope' => $scope,
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);

        RoleModuleAccess::create([
            'role_id' => $role->id,
            'module_code' => $module,
            'record_scope' => $scope,
            'actions' => $actions,
        ]);

        return $role;
    }
}
