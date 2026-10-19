<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoiceIndependentTotalTest extends TestCase
{
    public function test_current_invoice_total_does_not_subtract_previous_invoices(): void
    {
        $finance = file_get_contents(app_path('Services/OrderFinanceService.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/finance/create-invoice-modal.blade.php'));
        $pdf = file_get_contents(app_path('Services/InvoicePdfService.php'));

        $this->assertStringContainsString('$total = $grossTotal;', $finance);
        $this->assertStringNotContainsString('grossTotal - $previouslyInvoiced', $finance);
        $this->assertStringContainsString("'previously_invoiced' => 0", $finance);
        $this->assertStringNotContainsString('Previously invoiced', $modal);
        $this->assertStringNotContainsString('Amount to invoice', $modal);
        $this->assertStringNotContainsString("'Previously invoiced'", $pdf);
        $this->assertStringContainsString('private const LAYOUT_VERSION = 3;', $pdf);
    }
}
