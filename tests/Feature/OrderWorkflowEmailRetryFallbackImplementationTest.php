<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowEmailRetryFallbackImplementationTest extends TestCase
{
    public function test_order_workflow_email_handoffs_retry_three_times_and_offer_manual_completion(): void
    {
        $emailService = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $ordersIndex = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $ordersView = file_get_contents(resource_path('views/livewire/orders/index.blade.php'));

        $this->assertStringContainsString('private const DELIVERY_ATTEMPTS = 3;', $emailService);
        $this->assertStringContainsString('$this->email->deliver($message, $trackingId);', $emailService);
        $this->assertStringContainsString('$attempt <= self::DELIVERY_ATTEMPTS', $emailService);
        $this->assertStringContainsString("'delivery_attempts' => \$attemptsUsed", $emailService);
        $this->assertStringContainsString("'max_attempts' => self::DELIVERY_ATTEMPTS", $emailService);
        $this->assertStringContainsString('$trackingId = (string) Str::uuid();', $emailService);
        $this->assertStringContainsString('flowtrack.order_workflow_email.retry', $emailService);

        $this->assertStringContainsString('catch (EmailDeliveryException $exception)', $workflow);
        $this->assertStringContainsString("'attempts' => 3", $workflow);
        $this->assertStringContainsString('Due to some technical issue, the email could not be sent after ', $workflow);
        $this->assertStringContainsString('completeOrderWorkflowEmailTaskAfterFailure', $workflow);
        $this->assertStringContainsString('orderWorkflowEmailFallback', $index);
        $this->assertStringContainsString('catch (EmailDeliveryException $exception)', $ordersIndex);
        $this->assertStringContainsString('completeOrderWorkflowEmailTaskAfterFailure', $ordersIndex);
        $this->assertStringContainsString(':email-fallback="$orderWorkflowEmailFallback"', $ordersView);

        $this->assertStringContainsString('completeEmailHandoffAfterFailure', $actions);
        $this->assertStringContainsString("['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM']", $actions);
        $this->assertStringContainsString("(\$failure['attempts'] ?? 0) >= 3", $actions);
        $this->assertStringContainsString("'job.workflow_email_manual_completion'", $actions);
        $this->assertStringContainsString("'manual_delivery_required' => true", $actions);

        $this->assertStringContainsString('ft-prototype-email-preview--error', $modal);
        $this->assertStringContainsString('Download {{ $emailFallbackAttachmentLabel }}', $modal);
        $this->assertStringContainsString('Try email again', $modal);
        $this->assertStringContainsString('Complete task', $modal);
        $this->assertStringContainsString("route('documents.download', \$emailFallbackDocument)", $modal);
    }
}
