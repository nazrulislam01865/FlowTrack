<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderBillingInvoiceTaskResourceTest extends TestCase
{
    public function test_prepared_invoice_is_rendered_as_a_task_resource_like_artwork(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));

        $this->assertStringContainsString('@if($workflowInvoiceId > 0 || $resourceDocuments->isNotEmpty() || $taskLinks->isNotEmpty())', $row);
        $this->assertStringContainsString('wire:key="order-task-invoice-{{ $task->id }}-{{ $workflowInvoiceId }}"', $row);
        $this->assertStringContainsString('class="ft-order-task-resource-row is-latest-artwork ft-order-task-invoice-resource-row"', $row);
        $this->assertStringContainsString('<x-ui.file-type-badge :name="$workflowInvoiceDisplayName" class="ft-order-file-icon" />', $row);
        $this->assertStringContainsString('{{ $workflowInvoiceCreatorName }} · {{ \\App\\Support\\UserLocalTime::format($workflowInvoicePreparedAt, \'M j, Y, g:i A\') }} · Latest version', $row);
        $this->assertStringContainsString('<em class="ft-order-artwork-version-state is-latest">Latest</em>', $row);
        $this->assertStringContainsString("route('invoices.pdf.open', \$workflowInvoiceId)", $row);
        $this->assertStringContainsString("route('invoices.pdf.download', \$workflowInvoiceId)", $row);
    }

    public function test_invoice_resource_metadata_is_eager_loaded_and_added_to_order_detail_context(): void
    {
        $jobs = file_get_contents(app_path('Services/LegacyJobService.php'));
        $detail = file_get_contents(app_path('Services/OrderDetailViewService.php'));

        $this->assertStringContainsString("'invoices.creator:id,name'", $jobs);
        $this->assertStringContainsString("'creator_name' => (string) (\$preparedInvoice->creator?->name ?: 'FlowTrack')", $detail);
        $this->assertStringContainsString("'prepared_at' => \$preparedInvoice->created_at", $detail);
    }
}
