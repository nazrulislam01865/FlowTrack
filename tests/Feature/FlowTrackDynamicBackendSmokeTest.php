<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowTrackDynamicBackendSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_creates_super_admin_and_manager(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'superadmin@flowtrack.cn',
            'is_super_admin' => true,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'manager@flowtrack.cn',
            'status' => 'active',
        ]);
    }

    public function test_authenticated_super_admin_can_load_dynamic_bootstrap(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();

        $response = $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/bootstrap');

        $response->assertOk()
            ->assertJsonStructure([
                'currentUser',
                'clients',
                'jobs',
                'tasks',
                'documents',
                'notifications',
                'workflows',
                'taskPacks',
                'masterData',
                'capabilities',
                'accessControl',
                'summaryMetrics',
            ]);

        $payload = $response->json();
        $this->assertNotEmpty($payload['jobs']);
        $this->assertNotEmpty($payload['tasks']);
        $this->assertNotEmpty($payload['workflows']);
        $this->assertNotEmpty($payload['taskPacks']);
    }

    public function test_authenticated_manager_can_open_main_blade_pages(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $manager = User::query()->where('email', 'manager@flowtrack.cn')->firstOrFail();

        foreach ([
            '/dashboard',
            '/flowtrack/view/jobs',
            '/flowtrack/view/board',
            '/flowtrack/view/mywork',
            '/flowtrack/view/clients',
            '/flowtrack/view/documents',
            '/flowtrack/view/workflowsetup',
            '/flowtrack/view/masterdata',
            '/flowtrack/view/setup',
        ] as $uri) {
            $this->actingAs($manager)->get($uri)->assertOk()->assertSee('FlowTrack');
        }
    }
}
