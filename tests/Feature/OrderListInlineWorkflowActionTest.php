<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderListInlineWorkflowActionTest extends TestCase
{
    public function test_phase_list_next_action_reuses_order_detail_workflow_without_redirecting(): void
    {
        $index = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $presenter = file_get_contents(app_path('Services/OrderListPrototypeService.php'));
        $nextAction = file_get_contents(resource_path('views/components/orders/prototype-next-action.blade.php'));
        $view = file_get_contents(resource_path('views/livewire/orders/index.blade.php'));

        $this->assertStringContainsString("'next_task_id' =>", $presenter);
        $this->assertStringContainsString('openListWorkflowAction', $index);
        $this->assertStringContainsString('OrderWorkflowActionService::class', $index);
        $this->assertStringContainsString('afterDocumentAdded', $index);
        $this->assertStringContainsString('wire:click="openListWorkflowAction', $nextAction);
        $this->assertStringNotContainsString('wire:navigate x-on:click.stop>{{ data_get($row', $nextAction);
        $this->assertStringContainsString('x-jobs.order-detail.workflow-action-modal', $view);
        $this->assertStringContainsString('x-jobs.order-detail.document-modal', $view);
        $this->assertStringContainsString('ft-orders-index-livewire-root', $view);
        $this->assertStringContainsString('order-list-action-modal-host-', $view);
    }
}
