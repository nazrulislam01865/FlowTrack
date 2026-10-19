<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderTaskImmediateAssigneeUiTest extends TestCase
{
    public function test_order_task_actions_dispatch_the_confirmed_assignee_to_every_visible_task_surface(): void
    {
        $tasks = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTasks.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $resources = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTaskResources.php'));
        $inlineRuntime = file_get_contents(resource_path('js/components/inline-edit.js'));

        $this->assertStringContainsString("'task-assignee-updated'", $tasks);
        $this->assertStringContainsString('dispatchTaskAssigneeSync($updatedTask)', $tasks);
        $this->assertStringContainsString('dispatchTaskAssigneeSync($task->id)', $workflow);
        $this->assertStringContainsString('dispatchTaskAssigneeSync($task->id)', $resources);
        $this->assertStringContainsString('syncConfirmed(nextValue, nextDisplay, nextOptions = {})', $inlineRuntime);

        foreach ([
            resource_path('views/components/jobs/order-detail/task-row.blade.php'),
            resource_path('views/components/jobs/order-detail/stage-card.blade.php'),
            resource_path('views/components/jobs/order-detail/shipment/task-meta.blade.php'),
            resource_path('views/components/jobs/task-detail/properties.blade.php'),
        ] as $view) {
            $source = file_get_contents($view);
            $this->assertStringContainsString('x-on:task-assignee-updated.window', $source);
            $this->assertStringContainsString('syncConfirmed(', $source);
        }
    }
}
