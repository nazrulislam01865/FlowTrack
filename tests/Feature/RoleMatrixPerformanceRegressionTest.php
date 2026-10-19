<?php

namespace Tests\Feature;

use Tests\TestCase;

class RoleMatrixPerformanceRegressionTest extends TestCase
{
    public function test_permission_matrix_loads_only_selected_role_access_rows(): void
    {
        $component = file_get_contents(app_path('Livewire/Administration/Index.php'));
        $service = file_get_contents(app_path('Services/AdminService.php'));

        $this->assertStringContainsString('$roles = $service->roleOptions();', $component);
        $this->assertStringContainsString("\$selectedRole->load(['moduleAccess'", $component);
        $this->assertStringContainsString("->withCount(['users', 'primaryUsers', 'memberships'])", $service);
        $roleOptionsStart = strpos($service, 'public function roleOptions()');
        $roleOptionsEnd = strpos($service, 'public function notificationRules()', $roleOptionsStart);
        $this->assertNotFalse($roleOptionsStart);
        $this->assertNotFalse($roleOptionsEnd);
        $this->assertStringNotContainsString('withCount', substr($service, $roleOptionsStart, $roleOptionsEnd - $roleOptionsStart));
        $this->assertStringNotContainsString("Role::with(['moduleAccess','users'])", $service);
    }

    public function test_role_switch_forces_fresh_matrix_and_canonical_row_sync(): void
    {
        $view = file_get_contents(resource_path('views/livewire/administration/index.blade.php'));
        $component = file_get_contents(app_path('Livewire/Administration/Index.php'));

        $this->assertStringContainsString('wire:change="selectMatrixRole($event.target.value)"', $view);
        $this->assertStringContainsString('wire:key="permission-matrix-{{ $selectedRole->id }}"', $view);
        $this->assertStringContainsString('matrix-permission-synced', $view);
        $this->assertStringContainsString('withCanonicalMatrixState', $component);
    }
}
