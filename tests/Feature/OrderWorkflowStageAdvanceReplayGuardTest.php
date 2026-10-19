<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowStageAdvanceReplayGuardTest extends TestCase
{
    public function test_completed_workflow_actions_are_idempotent_across_stage_advance(): void
    {
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));

        $this->assertStringContainsString('if ($this->isCompletedWorkflowTask($locked)) {', $actions);
        $this->assertStringContainsString('private function isCompletedWorkflowTask(Task $task): bool', $actions);
        $this->assertStringContainsString('if (! $editingCompletedShipmentInformation && $this->ignoreCompletedWorkflowReplay($task)) {', $workflow);
        $this->assertStringContainsString('private function ignoreCompletedWorkflowReplay(Task $task): bool', $workflow);
        $this->assertStringContainsString('$this->syncOverviewWorkflowSelectionToCurrentPhase();', $workflow);
    }

    public function test_active_workflow_button_blocks_duplicate_livewire_clicks(): void
    {
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));

        $this->assertStringContainsString('wire:loading.attr="disabled"', $taskRow);
        $this->assertStringContainsString('wire:target="openOrderWorkflowAction({{ $task->id }})"', $taskRow);
    }
}
