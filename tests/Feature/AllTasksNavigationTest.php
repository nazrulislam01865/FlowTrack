<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllTasksNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tasks_is_restored_under_order_for_administrators_only(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));
        $mobile = file_get_contents(resource_path('views/layouts/partials/mobile-bottom.blade.php'));
        $board = file_get_contents(resource_path('views/livewire/board/index.blade.php'));

        $this->assertStringNotContainsString("Route::get('/board'", $routes);
        $this->assertStringContainsString("Route::get('/all-tasks'", $routes);
        $this->assertStringContainsString("middleware('super.admin')->name('all-tasks')", $routes);

        $this->assertStringContainsString('route="my-work" label="My Tasks"', $sidebar);
        $this->assertStringContainsString('@if($administrator)', $sidebar);
        $this->assertStringContainsString('route="all-tasks" label="All Tasks" icon="board" child', $sidebar);
        $this->assertGreaterThan(strpos($sidebar, 'route="jobs.index"'), strpos($sidebar, 'route="all-tasks"'));
        $this->assertStringNotContainsString('route="board"', $sidebar);

        // All Tasks is intentionally not exposed as a universal mobile navigation item.
        $this->assertStringNotContainsString("route('all-tasks')", $mobile);
        $this->assertStringNotContainsString("route('board')", $mobile);

        $this->assertStringContainsString('<h1>All Tasks</h1>', $board);
        $this->assertStringNotContainsString('Job Board', $board);
        $this->assertStringNotContainsString("setMode('jobs')", $board);
    }

    public function test_non_administrator_cannot_open_all_tasks_directly(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/all-tasks')->assertForbidden();
    }

    public function test_super_admin_can_open_all_tasks(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/all-tasks')->assertOk();
    }

    public function test_admin_role_can_open_all_tasks(): void
    {
        $adminRole = Role::query()->whereIn('slug', ['admin', 'administrator'])->firstOrFail();
        $user = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/all-tasks')->assertOk();
    }
}
