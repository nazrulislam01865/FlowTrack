<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowModalPreviewPerformanceTest extends TestCase
{
    public function test_workflow_modal_blade_does_not_perform_preview_or_document_io(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));

        $this->assertStringNotContainsString('OrderWorkflowEmailService::class', $modal);
        $this->assertStringNotContainsString('DocumentService::class', $modal);
        $this->assertStringNotContainsString('->invoiceEmailPreview(', $modal);
        $this->assertStringNotContainsString('->preparedWorkflowInvoice(', $modal);
        $this->assertStringContainsString('$emailHandoffPreview = (array) ($modalPreview[\'email_handoff_preview\'] ?? []);', $modal);
        $this->assertStringContainsString('$invoiceEmailPreview = (array) ($modalPreview[\'invoice_email_preview\'] ?? []);', $modal);
        $this->assertStringContainsString('wire:init="loadOrderWorkflowActionEmailPreview"', $modal);
    }

    public function test_initial_modal_open_defers_nested_email_body_rendering(): void
    {
        $jobWorkflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $orderList = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $previewService = file_get_contents(app_path('Services/Orders/OrderWorkflowActionModalPreviewService.php'));
        $workflowEmail = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $invoiceEmail = file_get_contents(app_path('Services/Orders/OrderInvoiceWorkflowEmailService.php'));

        $this->assertStringContainsString('->snapshot($task, $this->orderWorkflowActionPayload, false)', $jobWorkflow);
        $this->assertStringContainsString('->snapshot($task, $this->orderWorkflowActionPayload, false)', $orderList);
        $this->assertStringContainsString('->snapshot($task, $this->orderWorkflowActionPayload, true)', $jobWorkflow);
        $this->assertStringContainsString('->snapshot($task, $this->orderWorkflowActionPayload, true)', $orderList);
        $this->assertStringContainsString("['to_email', 'cc_emails', 'to_emails', 'customer_comment']", $jobWorkflow);
        $this->assertStringContainsString("['to_email', 'cc_emails', 'to_emails', 'customer_comment']", $orderList);

        $this->assertStringContainsString('$this->workflowEmail->preview($task, null, $payload, $includeHtml)', $previewService);
        $this->assertStringContainsString('$this->invoiceEmail->preview($task, null, $payload, $includeHtml)', $previewService);
        $this->assertStringContainsString('bool $includeHtml = true', $workflowEmail);
        $this->assertStringContainsString('$includeHtml && $viewData !== []', $workflowEmail);
        $this->assertStringContainsString('bool $includeHtml = true', $invoiceEmail);
        $this->assertStringContainsString('$viewData = $includeHtml', $invoiceEmail);
    }

    public function test_invoice_modal_uses_snapshot_metadata_instead_of_querying_invoice_from_blade(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $invoiceEmail = file_get_contents(app_path('Services/Orders/OrderInvoiceWorkflowEmailService.php'));

        $this->assertStringContainsString('$workflowInvoice = (array) ($modalPreview[\'workflow_invoice\'] ?? []);', $modal);
        $this->assertStringContainsString('$workflowInvoice[\'open_url\']', $modal);
        $this->assertStringContainsString('$workflowInvoice[\'download_url\']', $modal);
        $this->assertStringContainsString("'invoice' => [", $invoiceEmail);
        $this->assertStringContainsString("'open_url' => route('invoices.pdf.open', \$invoice)", $invoiceEmail);
        $this->assertStringContainsString("'download_url' => route('invoices.pdf.download', \$invoice)", $invoiceEmail);
    }
}
