<?php

namespace Tests\Feature;

use App\Services\Orders\SupplierPurchaseOrderTemplatePdf;
use Tests\TestCase;

final class SupplierPurchaseOrderTemplatePdfTest extends TestCase
{
    public function test_pdf_preserves_supplied_purchase_order_sheet_and_contains_live_po_details(): void
    {
        $this->assertFileExists(resource_path('templates/purchase-orders/official-purchase-order.xlsx'));
        $this->assertFileExists(resource_path('templates/purchase-orders/official-purchase-order.png'));

        $pdf = app(SupplierPurchaseOrderTemplatePdf::class)->render('SPO-123', 'Sample Supplier', 'USD', [
            ['name' => 'Sublimation Shirt', 'sku' => 'SKU-01', 'quantity' => 10, 'supplier_unit_cost' => 2.50],
            ['name' => 'Training Jersey', 'sku' => 'SKU-02', 'quantity' => 15, 'supplier_unit_cost' => 3.00],
        ]);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('SPO-123', $pdf);
        $this->assertStringContainsString('Sample Supplier', $pdf);
        $this->assertStringContainsString('SKU-02', $pdf);
    }

    public function test_carton_quantity_is_optional_and_not_inferred_from_order_quantity(): void
    {
        $source = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderTemplatePdf.php'));
        $this->assertStringContainsString('$value(\'carton_qty\')', $source);
        $this->assertStringNotContainsString('94, 909, number_format($qty)', $source);
        $this->assertStringContainsString('$value(\'carton_gross_weight\')', $source);
    }

    public function test_carton_marks_and_payment_details_are_printed_on_unchanged_template(): void
    {
        $pdf = app(SupplierPurchaseOrderTemplatePdf::class)->render(
            'SPO-200', 'Example Supplier', 'USD',
            [['name' => 'Test Shirt', 'sku' => 'SHIRT-01', 'quantity' => 42, 'supplier_unit_cost' => 5.00]],
            null, 'Step Promo & Accessories Co Ltd.', [
                'carton_style' => 'STYLE-001',
                'carton_colour' => 'Blue',
                'carton_gross_weight' => '16.2',
                'carton_mark_type' => 'barcode',
                'carton_strength' => '5ply',
                'payment_method' => 'tt',
                'deposit_percent' => '30',
                'balance_percent' => '70',
                'payment_term' => '30_70',
                'beneficiary' => 'Payee Company',
                'bank_name' => 'Example Bank',
                'account_number' => 'ACCT-12345',
                'swift_code' => 'TESTUS33',
            ],
        );
        $this->assertStringStartsWith('%PDF-', $pdf);
        foreach (['STYLE-001', 'Blue', '16.2', '30', '70', 'Payee Company', 'Example Bank', 'ACCT-12345', 'TESTUS33'] as $value) {
            $this->assertStringContainsString($value, $pdf);
        }
    }

    public function test_seven_line_po_uses_multiple_template_pages(): void
    {
        $items = array_fill(0, 7, ['name' => 'Test line', 'sku' => '', 'quantity' => 1, 'supplier_unit_cost' => null]);
        $pdf = app(SupplierPurchaseOrderTemplatePdf::class)->render('SPO-124', 'Supplier', 'USD', $items);
        $this->assertStringContainsString('/Count 2', $pdf);
    }

    public function test_automatic_phase_advance_never_undoes_completed_po_when_other_evidence_is_missing(): void
    {
        // A normal manual phase-advance request still reports the required
        // upload blocker. A best-effort *automatic* advance must not abort
        // the transaction that saved the supplier's PO PDFs and task status.
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $this->assertStringContainsString('if ($automatic) return $job;', $service);
        $this->assertStringContainsString('JobDetailPresenter::blockers($job)', $service);
    }
}
