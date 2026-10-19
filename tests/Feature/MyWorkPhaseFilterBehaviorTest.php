<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyWorkPhaseFilterBehaviorTest extends TestCase
{
    public function test_my_work_phase_filter_uses_the_canonical_seven_stage_order_runtime(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));
        $resolver = file_get_contents(app_path('Support/OrderStageResolver.php'));

        $this->assertStringContainsString('OrderWorkflowSetupService::fixedStages()', $service);
        $this->assertStringContainsString('OrderStageResolver::resolve(', $service);
        $this->assertStringContainsString('orderPhaseSourceIdsForName', $service);
        $this->assertStringContainsString("orWhereIn('workflow_phases.source_workflow_phase_id'", $service);
        $this->assertStringContainsString("'phase' => (string) \$taskStage['short_name']", $service);
        $this->assertStringContainsString("order intake", $resolver);
        $this->assertStringContainsString("'name' => (string) \$stage['name']", $resolver);
    }

    public function test_metric_cards_and_toolbar_filters_are_mutually_exclusive(): void
    {
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringContainsString('public bool $hideCompleted = false;', $component);
        $this->assertStringContainsString('public function setMetricFilter', $component);
        $this->assertStringContainsString("\$this->search = '';", $component);
        $this->assertStringContainsString("\$this->phaseFilter = '';", $component);
        $this->assertStringContainsString('clearMetricFilterForToolbar', $component);
        $this->assertStringContainsString('class="phase-toggle', $view);
        $this->assertStringContainsString('wire:click="setPhaseFilter(', $view);
        $this->assertStringNotContainsString('wire:model.live="phaseFilter"', $view);
        $this->assertStringContainsString('wire:click="clearFilters"', $view);
        $this->assertStringNotContainsString('Showing {{ $workGroups->count() }} of {{ $workPaginator->total() }} matching Orders', $view);
        $this->assertStringNotContainsString('Results update after 650 ms', $view);
    }
}
