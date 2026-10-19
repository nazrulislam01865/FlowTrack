<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminUserAssignmentSearchTest extends TestCase
{
    public function test_users_and_assignments_has_server_side_search_without_changing_user_actions(): void
    {
        $component = file_get_contents(app_path('Livewire/Administration/Index.php'));
        $service = file_get_contents(app_path('Services/AdminService.php'));
        $view = file_get_contents(resource_path('views/livewire/administration/index.blade.php'));

        $this->assertStringContainsString("public string $userSearch = ''", $component);
        $this->assertStringContainsString('updatedUserSearch', $component);
        $this->assertStringContainsString("resetPage('usersPage')", $component);
        $this->assertStringContainsString("paginateUsers(10, 'usersPage', $this->userSearch)", $component);

        $this->assertStringContainsString("where('name', 'like', $like)", $service);
        $this->assertStringContainsString("orWhere('email', 'like', $like)", $service);
        $this->assertStringNotContainsString("orWhereHas('department'", $service);
        $this->assertStringNotContainsString("orWhereHas('roles'", $service);
        $this->assertStringNotContainsString("orWhereHas('role'", $service);
        $this->assertStringContainsString("where('job_title', 'like', $like)", $service);

        $this->assertStringContainsString('wire:model.live.debounce.500ms="userSearch"', $view);
        $this->assertStringContainsString('Search user by name, email or position', $view);
        $this->assertStringContainsString('wire:click="clearUserSearch"', $view);
        $this->assertStringContainsString('wire:click="openUser"', $view);
        $this->assertStringContainsString("route('users.edit'", $view);
        $this->assertStringContainsString('wire:click="deleteUser(', $view);
    }
}
