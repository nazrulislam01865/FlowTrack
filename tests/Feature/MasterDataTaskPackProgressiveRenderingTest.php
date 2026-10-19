<?php

namespace Tests\Feature;

use Tests\TestCase;

class MasterDataTaskPackProgressiveRenderingTest extends TestCase
{
    public function test_master_data_loads_only_the_selected_group_rows_after_the_shell(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $view = \Tests\Support\AdministrationPhase7Source::masterDataView();

        $this->assertStringContainsString('public bool $recordsReady = false;', $component);
        $this->assertStringContainsString('function loadMasterRecords()', $component);
        $this->assertStringContainsString('? $service->paginate($this->group, $this->search, 30)', $component);
        $this->assertStringContainsString('$this->showModal && $this->group === \'product\'', $component);
        $this->assertStringContainsString('wire:init="loadMasterRecords"', $view);
        $this->assertStringContainsString('$groupCounts[$key] ?? 0', $view);
        $this->assertStringNotContainsString('MasterRecord::', $view);
    }

    public function test_task_pack_list_does_not_load_form_options(): void
    {
        $component = file_get_contents(app_path('Livewire/TaskPackSetup/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/task-pack-setup/index.blade.php'));

        $this->assertStringContainsString('public bool $packsReady = false;', $component);
        $this->assertStringContainsString('function loadTaskPacks()', $component);
        $this->assertStringContainsString('wire:init="loadTaskPacks"', $view);
        $this->assertStringNotContainsString('MasterRecord::', $component);
        $this->assertStringNotContainsString('User::', $component);
    }

    public function test_task_pack_form_loads_each_shared_option_type_on_demand(): void
    {
        $component = file_get_contents(app_path('Livewire/TaskPackSetup/Form.php'));
        $view = file_get_contents(resource_path('views/livewire/task-pack-setup/form.blade.php'));

        $this->assertStringContainsString('public bool $optionsReady = false;', $component);
        $this->assertStringContainsString('function loadTaskPackOptions()', $component);
        $this->assertStringContainsString("app(FilterOptionService::class)->options(\$user, 'department-records', 'task-pack-setup'", $component);
        $this->assertStringContainsString('$master->active(\'priority\')', $component);
        $this->assertStringContainsString("app(FilterOptionService::class)->options(\$user, 'document-category-records', 'task-pack-setup'", $component);
        $this->assertStringContainsString('wire:init="loadTaskPackOptions"', $view);
        $this->assertStringContainsString('@if($optionsReady)', $view);
        $this->assertStringContainsString('context="task-pack-setup"', $view);
        $this->assertStringContainsString('action="setTaskPackAssignee"', $view);
        $this->assertStringContainsString(':fixed-menu="true"', $view);
        $this->assertStringContainsString('function setTaskPackAssignee', $component);
        $this->assertStringNotContainsString('wire:model="tasks.{{ $index }}.default_assignee_id"', $view);
    }

    public function test_workflow_only_loads_task_pack_options_when_its_phase_modal_is_open(): void
    {
        $component = file_get_contents(app_path('Livewire/WorkflowSetup/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/workflow-setup/index.blade.php'));

        $this->assertStringContainsString("'taskPacks' => \$this->showPhaseModal", $component);
        $this->assertStringNotContainsString("'documentCategories' => \$this->showPhaseModal", $component);
        $this->assertStringNotContainsString("active('document_category')", $component);
        $this->assertStringNotContainsString('MasterRecord::', $component);
        $this->assertStringNotContainsString('Required document', $view);
        $this->assertStringNotContainsString('wire:model="allowJobStart"', $view);
        $this->assertStringNotContainsString('wire:model="isSkippable"', $view);
        $this->assertStringNotContainsString('wire:model="autoAdvanceOnReady"', $view);
        $this->assertStringContainsString('wire:model="phaseActive"', $view);
    }
}
