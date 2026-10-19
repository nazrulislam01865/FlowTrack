<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoicePdfCompanyClientProductPresentationTest extends TestCase
{
    public function test_invoice_pdf_uses_branding_company_client_and_product_details(): void
    {
        $pdf = file_get_contents(app_path('Services/InvoicePdfService.php'));
        $finance = file_get_contents(app_path('Services/OrderFinanceService.php'));
        $document = file_get_contents(app_path('Support/SimplePdfDocument.php'));

        $this->assertStringContainsString('$branding = app(BrandingService::class)->current();', $pdf);
        $this->assertStringContainsString('$logoPath = $this->brandingLogoPath($branding);', $pdf);
        $this->assertStringContainsString("'BILL TO'", $pdf);
        $this->assertStringContainsString("'PRODUCT'", $pdf);
        $this->assertStringContainsString("'QTY'", $pdf);
        $this->assertStringContainsString("'UNIT PRICE'", $pdf);
        $this->assertStringContainsString("'TOTAL'", $pdf);
        $this->assertStringContainsString("'client_snapshot' => app(ClientInvoiceProfileService::class)->invoiceSnapshot(\$lockedJob->client)", $finance);
        $this->assertStringContainsString('public function image(string $path', $document);
    }

    public function test_invoice_model_preserves_company_and_client_snapshots(): void
    {
        $invoice = file_get_contents(app_path('Models/Invoice.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_15_173000_add_client_snapshot_and_invoice_layout_version.php'));

        $this->assertStringContainsString("'company_snapshot' => 'array'", $invoice);
        $this->assertStringContainsString("'client_snapshot' => 'array'", $invoice);
        $this->assertStringContainsString("'pdf_layout_version' => 'integer'", $invoice);
        $this->assertStringContainsString("\$table->json('client_snapshot')", $migration);
        $this->assertStringContainsString("\$table->unsignedSmallInteger('pdf_layout_version')", $migration);
    }
}
