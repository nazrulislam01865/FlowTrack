<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierNativeProductPresentationContractTest extends TestCase
{
    public function test_native_product_catalog_stays_supplier_scoped_and_permission_filtered(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SupplierBridge/SupplierNativeProductApi.php'));
        $this->assertStringContainsString('product_supplier_links.supplier_id', $source);
        $this->assertStringContainsString("$".'this->assignedQuery($workspace, $supplierId)', $source);
        $this->assertStringContainsString("$".'allowed(\'availability\')', $source);
        $this->assertStringContainsString("$".'allowed(\'documents\')', $source);
        $this->assertStringContainsString("$".'this->assignedProduct($workspace, $supplierId, $key)', $source);
        $this->assertStringContainsString('COUNT(*) as total', $source);
    }
}
