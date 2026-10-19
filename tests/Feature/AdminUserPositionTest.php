<?php

namespace Tests\Feature;

use App\Livewire\Administration\Index;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserPositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_and_update_a_users_company_position(): void
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $role = Role::query()
            ->where('workspace_id', 1)
            ->where('slug', 'general-team-member')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(Index::class)
            ->set('tab', 'users')
            ->call('openUser')
            ->set('name', 'Amina Rahman')
            ->set('position', 'Senior Production Coordinator')
            ->set('email', 'amina.rahman@example.com')
            ->set('roleIds', [(string) $role->id])
            ->set('password', 'password1234')
            ->set('passwordConfirmation', 'password1234')
            ->call('saveUser')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'amina.rahman@example.com')->firstOrFail();
        $membership = WorkspaceMembership::query()
            ->where('workspace_id', 1)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame('Senior Production Coordinator', $membership->job_title);

        $component
            ->call('openUser', $user->id)
            ->assertSet('position', 'Senior Production Coordinator')
            ->set('position', 'Production Manager')
            ->set('password', '')
            ->set('passwordConfirmation', '')
            ->call('saveUser')
            ->assertHasNoErrors();

        $this->assertSame(
            'Production Manager',
            $membership->fresh()->job_title,
        );
    }
}
