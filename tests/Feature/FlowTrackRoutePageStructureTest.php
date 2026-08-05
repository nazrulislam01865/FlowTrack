<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowTrackRoutePageStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_flowtrack_url_renders_only_its_route_page_shell(): void
    {
        $this->seed(FlowTrackSeeder::class);
        $user = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();

        $cases = [
            '/flowtrack/view/dashboard' => 'dashboard',
            '/flowtrack/view/jobs' => 'jobs',
            '/flowtrack/view/clients' => 'clients',
            '/flowtrack/view/board' => 'board',
            '/flowtrack/view/mywork' => 'mywork',
            '/flowtrack/view/documents' => 'documents',
            '/flowtrack/view/reports' => 'reports',
            '/flowtrack/view/workflowsetup' => 'workflowsetup',
            '/flowtrack/view/taskpacksetup' => 'taskpacksetup',
            '/flowtrack/view/masterdata' => 'masterdata',
            '/flowtrack/view/setup' => 'setup',
            '/flowtrack/view/profile' => 'profile',
        ];

        foreach ($cases as $url => $page) {
            $response = $this->actingAs($user)->get($url)->assertOk();
            $response->assertSee('id="page-'.$page.'"', false);
            $response->assertSee('data-route-page="true"', false);

            foreach (array_unique(array_values($cases)) as $otherPage) {
                if ($otherPage !== $page) {
                    $response->assertDontSee('id="page-'.$otherPage.'"', false);
                }
            }
        }
    }

    public function test_record_routes_keep_the_parent_page_shell_for_design_parity(): void
    {
        $this->seed(FlowTrackSeeder::class);
        $user = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();

        $this->actingAs($user)->get('/flowtrack/view/jobs/JOB-2026-00125')
            ->assertOk()
            ->assertSee('id="page-jobs"', false);

        $this->actingAs($user)->get('/flowtrack/view/tasks/TASK-001')
            ->assertOk()
            ->assertSee('id="page-board"', false);
    }
    public function test_configuration_form_routes_cannot_be_swallowed_by_task_detail_route(): void
    {
        $this->seed(FlowTrackSeeder::class);
        $user = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();

        $this->actingAs($user)->get('/flowtrack/view/task-packs/new')
            ->assertOk()
            ->assertSee('id="page-taskpacksetup"', false);

        $this->actingAs($user)->get('/flowtrack/view/workflows/1/copy')
            ->assertOk()
            ->assertSee('id="page-workflowsetup"', false);

        $this->actingAs($user)->get('/flowtrack/view/tasks/pack-new')
            ->assertRedirect(route('flowtrack.view.task-packs.create'));

        $this->actingAs($user)->get('/flowtrack/view/tasks/pack-edit-17')
            ->assertRedirect(route('flowtrack.view.task-packs.edit', ['record' => '17']));
    }

}
