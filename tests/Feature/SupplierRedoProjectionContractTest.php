<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\SupplierOrderProjection;
use Tests\TestCase;

final class SupplierRedoProjectionContractTest extends TestCase
{
    public function test_redo_metadata_is_bounded_to_one_order_lookup_and_supplier_safe_fields(): void
    {
        $source = file_get_contents(app_path('Services/SupplierBridge/SupplierOrderProjection.php'));
        $this->assertSame(9, SupplierOrderProjection::FORMAT_VERSION);
        $this->assertStringContainsString("if (\$order->start_handling === 'Redo')", $source);
        $this->assertStringContainsString("->where('redo.redo_order_id', \$orderId)", $source);
        $this->assertStringContainsString("'original.job_number as original_number'", $source);
        $this->assertStringContainsString("['redo' => \$redo]", $source);
        $this->assertStringContainsString("'affected_quantity' => (int) \$record->affected_quantity", $source);
        $this->assertStringContainsString("'redo_quantity' => (int) \$record->redo_quantity", $source);
        $this->assertStringNotContainsString("'customer_impact' => \$record", $source);
        $this->assertStringContainsString("'redo.supplier_adjustment_value'", $source);
        $this->assertStringContainsString("'redo.supplier_redo_charge'", $source);
        $this->assertStringContainsString("'redo.total_supplier_recovery'", $source);
        $this->assertStringContainsString('$financialSupplierId === $supplierId', $source);
        $this->assertStringContainsString('$selectedSupplierId === 0', $source);
        $this->assertStringContainsString("->where('supplier_id', '!=', \$supplierId)", $source);
        $this->assertStringContainsString("\$redo['supplier_adjustment'] = [", $source);
        $this->assertStringContainsString("'courier_freight' => \$courierFreight", $source);
        $this->assertStringContainsString('$record->freight_amount - $courierFreight', $source);
        $this->assertStringNotContainsString("'internal_instructions' => \$record", $source);
    }
}
