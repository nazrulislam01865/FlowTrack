<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderHoldActivityLockImplementationTest extends TestCase
{
    public function test_order_hold_is_optional_mention_aware_and_blocks_order_mutations_until_release(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/hold-modal.blade.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));
        $stateCard = file_get_contents(resource_path('views/components/jobs/order-detail/hold-status-card.blade.php'));
        $blockedModal = file_get_contents(resource_path('views/components/jobs/order-detail/hold-blocked-modal.blade.php'));
        $detail = file_get_contents(resource_path('views/components/jobs/detail.blade.php'));
        $guard = file_get_contents(resource_path('js/components/order-hold-guard.js'));
        $requestFeedback = file_get_contents(resource_path('js/components/order-hold-request-feedback.js'));
        $holdConcern = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderHold.php'));
        $workflowSection = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $orderWorkflowConcern = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $taskDetail = file_get_contents(resource_path('views/components/jobs/task-detail.blade.php'));
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $inlineEdits = file_get_contents(app_path('Livewire/Concerns/HandlesInlineEdits.php'));
        $holdService = file_get_contents(app_path('Services/Orders/OrderHoldService.php'));
        $taskService = file_get_contents(app_path('Services/TaskService.php'));
        $shipmentService = file_get_contents(app_path('Services/OrderShipmentService.php'));
        $financeService = file_get_contents(app_path('Services/OrderFinanceService.php'));
        $documentService = file_get_contents(app_path('Services/DocumentService.php'));
        $lifecycle = file_get_contents(app_path('Services/Orders/OrderLifecycleService.php'));

        $this->assertStringContainsString('Hold from <span aria-hidden="true">*</span>', $modal);
        $this->assertStringContainsString('Reason <span class="ft-order-hold-optional">Optional</span>', $modal);
        $this->assertStringContainsString('data-mention-users=', $modal);
        $this->assertStringContainsString('Type <b>@</b> to mention a user', $modal);
        $this->assertStringNotContainsString('Hold type', $modal);
        $this->assertStringContainsString("'orderHoldReason' => ['nullable', 'string', 'max:500']", $holdConcern);

        $this->assertStringContainsString('hold-status-card', $planning);
        $this->assertStringContainsString('Order activities are paused', $stateCard);
        $this->assertStringContainsString('Release hold', $stateCard);
        $this->assertStringContainsString('This activity is currently locked', $blockedModal);
        $this->assertStringContainsString('release the hold first', $blockedModal);
        $this->assertStringContainsString('flowtrack:order-hold-state', $detail);
        $this->assertStringContainsString('$this->dispatch(\'order-hold-runtime-changed\', orderId: (int) $this->selectedJobId, held: true)', $holdConcern);
        $this->assertStringContainsString('$this->dispatch(\'order-hold-runtime-changed\', orderId: (int) $this->selectedJobId, held: false)', $holdConcern);
        $this->assertStringContainsString('->to(component: \\App\\Livewire\\Jobs\\OrderWorkflowSection::class)', $holdConcern);
        $this->assertStringContainsString("#[On('order-hold-runtime-changed')]", $workflowSection);
        $this->assertStringContainsString('public function refreshOrderHoldRuntime(int $orderId, bool $held): void', $workflowSection);
        $this->assertStringContainsString('guardInteraction($event)', $detail);
        $this->assertStringContainsString('createOrderHoldGuard', $guard);
        $this->assertStringContainsString('bootOrderHoldRequestFeedback', $requestFeedback);
        $this->assertStringContainsString('This Order is on hold. Release the hold before performing any activity.', $requestFeedback);
        $this->assertStringContainsString("window.addEventListener('flowtrack:order-held-blocked', show)", $requestFeedback);
        $this->assertStringContainsString('$canEditTask = $mayEditTask && ! $taskOrderIsOnHold;', $taskDetail);
        $this->assertStringContainsString('ft-task-held-action', $taskDetail);
        $this->assertStringContainsString('$orderOnHold = (bool)', $taskRow);
        $this->assertStringContainsString('ft-order-task-hold-locked-action', $taskRow);
        $this->assertStringContainsString('showHoldBlocked(@js($workflowActionLabel))', $taskRow);
        $this->assertStringContainsString('OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id)', $orderWorkflowConcern);
        $this->assertStringContainsString("$this->dispatch('flowtrack:order-held-blocked', action: $label)", $inlineEdits);

        $this->assertStringContainsString('notifyMentionedUsers(', $holdService);
        $this->assertStringContainsString('public function assertNotHeld(', $holdService);
        $this->assertStringContainsString('OrderHoldService::class)->assertNotHeld', $taskService);
        $this->assertStringContainsString('OrderHoldService::class)->assertNotHeld', $shipmentService);
        $this->assertStringContainsString('OrderHoldService::class)->assertNotHeld', $financeService);
        $this->assertStringContainsString('OrderHoldService::class)->assertNotHeld', $documentService);
        $this->assertStringContainsString('OrderHoldService::class)->assertNotHeld', $lifecycle);
    }
}
