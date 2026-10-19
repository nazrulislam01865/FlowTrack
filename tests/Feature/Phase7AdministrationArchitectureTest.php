<?php

namespace Tests\Feature;

use Tests\Support\AdministrationPhase7Source;
use Tests\TestCase;

class Phase7AdministrationArchitectureTest extends TestCase
{
    public function test_master_data_and_client_coordinators_use_phase_7_concerns_and_actions(): void
    {
        $master = AdministrationPhase7Source::masterData();
        $clients = AdministrationPhase7Source::clients();

        $this->assertStringContainsString('trait ManagesMasterEditor', $master);
        $this->assertStringContainsString('trait ManagesProductCatalog', $master);
        $this->assertStringContainsString('trait ManagesProductTaxonomy', $master);
        $this->assertStringContainsString('DeleteMasterRecordAction::class', $master);
        $this->assertStringContainsString('DeleteProductCategoriesAction::class', $master);

        $this->assertStringContainsString('trait ManagesClientProfile', $clients);
        $this->assertStringContainsString('trait ManagesClientEditing', $clients);
        $this->assertStringContainsString('trait ManagesClientLifecycle', $clients);
        $this->assertStringContainsString('SaveClientProfileAction::class', $clients);
        $this->assertStringContainsString('PermanentlyDeleteClientAction::class', $clients);
    }

    public function test_setup_screens_share_phase_7_primitives(): void
    {
        $workflow = file_get_contents(resource_path('views/livewire/workflow-setup/index.blade.php'));
        $taskPacks = file_get_contents(resource_path('views/livewire/task-pack-setup/index.blade.php'));
        $orderWorkflow = file_get_contents(resource_path('views/livewire/order-workflow-setup/index.blade.php'));
        $master = AdministrationPhase7Source::masterDataView();

        foreach ([
            'views/components/setup/page-header.blade.php',
            'views/components/setup/list.blade.php',
            'views/components/setup/editor-panel.blade.php',
            'views/components/setup/editor-modal.blade.php',
            'views/components/setup/pagination.blade.php',
            'views/components/setup/safe-delete-modal.blade.php',
            'views/components/setup/color-picker.blade.php',
        ] as $relative) {
            $this->assertFileExists(resource_path($relative));
        }

        $this->assertStringContainsString('<x-setup.page-header', $workflow);
        $this->assertStringContainsString('<x-setup.list', $workflow);
        $this->assertStringContainsString('<x-setup.editor-panel', $workflow);
        $this->assertStringContainsString('<x-setup.safe-delete-modal', $workflow);
        $this->assertStringContainsString('<x-setup.editor-modal', $workflow);
        $this->assertStringContainsString('<x-setup.page-header', $taskPacks);
        $this->assertStringContainsString('<x-setup.list', $taskPacks);
        $this->assertStringContainsString('<x-setup.safe-delete-modal', $taskPacks);
        $this->assertStringContainsString('<x-setup.color-picker', $orderWorkflow);
        $this->assertStringContainsString('<x-setup.color-picker', $master);
    }

    public function test_master_data_runtime_colors_keep_using_the_shared_dynamic_color_contract(): void
    {
        $masterView = AdministrationPhase7Source::masterDataView();
        $workflowView = file_get_contents(resource_path('views/livewire/workflow-setup/index.blade.php'));
        $taskPackView = file_get_contents(resource_path('views/livewire/task-pack-setup/index.blade.php'));

        $this->assertStringContainsString('MasterColor::style(', $masterView);
        $this->assertStringContainsString('MasterColor::style(', $workflowView);
        $this->assertStringContainsString('MasterColor::style(', $taskPackView);
        $this->assertStringContainsString('<x-setup.color-picker', $masterView);
    }
}
