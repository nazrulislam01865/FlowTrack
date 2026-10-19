<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\ClientService;
use App\Services\FilterOptionService;
use App\Services\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalReferenceRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_directory_is_workspace_wide_once_client_view_is_granted(): void
    {
        $user = $this->userWithAccess([
            'clients' => ['scope' => 'assigned_jobs', 'actions' => ['view', 'create']],
        ]);
        $client = Client::create(['name' => 'Shared Client', 'code' => 'SHARED-1', 'is_active' => true]);

        $this->assertTrue(app(ClientService::class)->visibleQuery($user)->whereKey($client->id)->exists());
        $this->assertSame('all_records', app(AccessControlService::class)->scope($user, 'clients'));
    }

    public function test_create_order_can_use_shared_clients_without_client_module_view_permission(): void
    {
        $user = $this->userWithAccess([
            'jobs' => ['scope' => 'assigned_jobs', 'actions' => ['create']],
            'clients' => ['scope' => 'none', 'actions' => []],
        ]);
        $client = Client::create(['name' => 'Order Reference Client', 'code' => 'ORDER-REF', 'is_active' => true]);

        $this->assertFalse(app(ClientService::class)->visibleQuery($user)->whereKey($client->id)->exists());

        $items = app(FilterOptionService::class)->options($user, 'clients', 'create-job', '', null, 20);
        $this->assertTrue($items->contains(fn (array $item) => (int) $item['id'] === (int) $client->id));
    }

    public function test_new_client_becomes_immediately_available_to_order_creator(): void
    {
        $user = $this->userWithAccess([
            'jobs' => ['scope' => 'assigned_jobs', 'actions' => ['create']],
            'clients' => ['scope' => 'assigned_jobs', 'actions' => ['view', 'create']],
        ]);

        $before = app(FilterOptionService::class)->options($user, 'clients', 'create-job', '', null, 20);
        $this->assertCount(0, $before);

        $client = Client::create([
            'name' => 'Just Added Client',
            'code' => 'JUST-ADDED',
            'created_by' => $user->id,
            'is_active' => true,
        ]);

        $after = app(FilterOptionService::class)->options($user, 'clients', 'create-job', '', $client->id, 20);
        $this->assertTrue($after->contains(fn (array $item) => (int) $item['id'] === (int) $client->id));
    }

    public function test_universal_module_action_is_not_silently_disabled_by_none_scope(): void
    {
        $user = $this->userWithAccess([
            'clients' => ['scope' => 'none', 'actions' => ['view']],
        ]);

        $access = app(AccessControlService::class);
        $this->assertTrue($access->can($user, 'clients', 'view'));
        $this->assertSame('all_records', $access->scope($user, 'clients'));
    }

    public function test_shared_clients_do_not_expand_operational_job_scope(): void
    {
        $user = $this->userWithAccess([
            'clients' => ['scope' => 'assigned_jobs', 'actions' => ['view']],
            'jobs' => ['scope' => 'assigned_jobs', 'actions' => ['view']],
        ]);
        $other = User::factory()->create(['is_active' => true]);
        $client = Client::create(['name' => 'Universal Client', 'code' => 'UNIVERSAL', 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'Test Workflow', 'slug' => 'test-workflow', 'is_active' => true]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Start',
            'short_name' => 'Start',
            'allow_job_start' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'JOB-PRIVATE',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $other->id,
            'coordinator_id' => $other->id,
            'title' => 'Other user job',
        ]);

        $this->assertTrue(app(ClientService::class)->visibleQuery($user)->whereKey($client->id)->exists());
        $this->assertFalse(app(JobService::class)->visibleQuery($user)->whereKey($job->id)->exists());
    }

    private function userWithAccess(array $modules): User
    {
        $role = Role::create([
            'workspace_id' => 1,
            'name' => 'Role '.uniqid(),
            'slug' => 'role-'.uniqid(),
            'code' => 'ROLE_'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'description' => 'Test role',
            'default_scope' => 'assigned_jobs',
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);

        foreach ($modules as $module => $config) {
            RoleModuleAccess::create([
                'role_id' => $role->id,
                'module_code' => $module,
                'record_scope' => $config['scope'],
                'actions' => $config['actions'],
            ]);
        }

        return User::factory()->create([
            'role_id' => $role->id,
            'is_super_admin' => false,
            'is_active' => true,
        ])->load('role');
    }
}
