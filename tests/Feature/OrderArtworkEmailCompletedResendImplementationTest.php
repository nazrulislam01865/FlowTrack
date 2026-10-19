<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderArtworkEmailCompletedResendImplementationTest extends TestCase
{
    public function test_completed_artwork_handoff_keeps_failed_delivery_retryable_and_activity_composers_stack_actions(): void
    {
        $service = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $flowJob = file_get_contents(app_path('Models/FlowJob.php'));
        $readService = file_get_contents(app_path('Services/LegacyJobService.php'));
        $detailService = file_get_contents(app_path('Services/OrderDetailViewService.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $detailCss = file_get_contents(resource_path('css/modules/orders/detail/detail-02.css'));
        $activityCss = file_get_contents(resource_path('css/modules/application/08-clients-documents-admin.css'));

        $this->assertStringContainsString("'job.artwork_email_failed_to_order_team'", $service);
        $this->assertStringContainsString("'job.workflow_email_skipped'", $flowJob);
        $this->assertStringContainsString("'not_sent'", $service);
        $this->assertStringContainsString("'intended_to'", $service);
        $this->assertStringContainsString("'to_emails' => \$toEmails->values()->all()", $service);
        $this->assertStringContainsString('public function artworkHandoffDeliveryStatus(', $service);
        $this->assertStringContainsString('public function resendCompletedArtworkHandoff(', $service);
        $this->assertStringContainsString("'to_emails' => \$toEmails->implode(', ')", $service);

        $this->assertStringContainsString('public function workflowEmailActivities()', $flowJob);
        $this->assertStringContainsString('workflowEmailActivities:activities.id', $readService);
        $this->assertStringContainsString("'workflowEmailStatuses' => \$workflowEmailStatuses", $detailService);

        $this->assertStringContainsString('public function resendCompletedArtworkEmail(int $taskId): void', $workflow);
        $this->assertStringContainsString('resendCompletedArtworkHandoff($task, auth()->user())', $workflow);
        $this->assertStringContainsString('Artwork email failed again.', $workflow);
        $this->assertStringContainsString('catch (HttpExceptionInterface $exception)', $workflow);
        $this->assertStringContainsString('Artwork email cannot be resent because Order email sending is disabled.', $workflow);

        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $this->assertStringContainsString('for the current artwork ({{ $artworkVersionLabel }})', $modal);
        $this->assertStringNotContainsString('$artworkVersion }}', $modal);

        $this->assertStringContainsString('Email Sent', $taskRow);
        $this->assertStringContainsString('Email Failed', $taskRow);
        $this->assertStringContainsString('Email Not Sent', $taskRow);
        $this->assertStringContainsString('resendCompletedArtworkEmail({{ $task->id }})', $taskRow);
        $this->assertStringContainsString('>Resend</button>', $taskRow);

        $this->assertStringContainsString('.ft-order-task-email-status.is-failed', $detailCss);
        $this->assertStringContainsString('.ft-order-task-email-status.is-sent', $detailCss);
        $this->assertStringContainsString('.ft-order-task-email-status.is-not-sent', $detailCss);
        $this->assertStringContainsString('grid-template-columns: 36px minmax(0, 1fr) !important;', $detailCss);
        $this->assertStringContainsString('.ft-comment-composer.ft-friendly-composer > .ft-new-job-btn', $activityCss);
        $this->assertStringContainsString('grid-column: 2 !important;', $activityCss);
    }
}
