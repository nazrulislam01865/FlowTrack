<?php

namespace Tests\Feature;

use App\Services\AccessControlService;
use Tests\TestCase;

class RoleMatrixGranularConfigurationPermissionsTest extends TestCase
{
    public function test_workflow_and_master_data_expose_real_granular_actions(): void
    {
        $this->assertSame(
            AccessControlService::ACTIONS,
            AccessControlService::supportedActions('workflow')
        );

        $this->assertSame(
            AccessControlService::ACTIONS,
            AccessControlService::supportedActions('taskpacks')
        );

        $this->assertSame(
            AccessControlService::ACTIONS,
            AccessControlService::supportedActions('masterdata')
        );
    }

    public function test_enabled_configuration_routes_use_granular_permissions(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("middleware('permission:workflow.view')->name('workflow.setup')", $routes);
        $this->assertStringContainsString("middleware('permission:workflow.create')->name('workflow.create')", $routes);
        $this->assertStringContainsString("middleware('permission:workflow.update')->whereNumber('workflow')->name('workflow.edit')", $routes);
        $this->assertStringContainsString("middleware('permission:taskpacks.view')->name('task-pack.setup')", $routes);
        $this->assertStringContainsString("middleware('permission:taskpacks.create')->name('task-pack.create')", $routes);
        $this->assertStringContainsString("middleware('permission:taskpacks.update')->whereNumber('taskPack')->name('task-pack.edit')", $routes);
        $this->assertStringContainsString("Route::get('/master-data', MasterDataController::class)->name('master-data')", $routes);
        $controller = file_get_contents(app_path('Http/Controllers/MasterDataController.php'));
        $this->assertStringContainsString('MasterDataService::permissionModuleForType($group)', $controller);
        $this->assertStringContainsString("canModule(\$module, 'view')", $controller);
    }

    public function test_every_matrix_action_is_selectable_for_every_module(): void
    {
        foreach (array_keys(AccessControlService::MODULES) as $module) {
            $this->assertSame(AccessControlService::ACTIONS, AccessControlService::supportedActions($module));
        }

        $view = file_get_contents(resource_path('views/livewire/administration/index.blade.php'));
        $this->assertStringNotContainsString('Not applicable for this module', $view);
    }
}
