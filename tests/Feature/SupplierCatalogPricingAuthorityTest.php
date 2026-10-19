<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SupplierCatalogPricingAuthorityTest extends TestCase
{
    public function test_supplier_catalog_and_approval_baseline_never_use_product_or_customer_price_fallback(): void
    {
        $catalog = file_get_contents(__DIR__.'/../../app/Services/SupplierBridge/SyncSupplier.php');
        $pricing = file_get_contents(__DIR__.'/../../app/Services/SupplierBridge/SupplierPricing.php');

        $this->assertStringContainsString("DB::table('supplier_product_prices')", $catalog);
        $this->assertStringContainsString("->where('supplier_id', $id)", $catalog);
        $this->assertStringContainsString("'supplier_prices' => $".'rows', $catalog);
        $this->assertStringNotContainsString('$product->supplierPriceBreakpoints()', $catalog);
        $this->assertStringNotContainsString('customerPriceBreakpoints()', $catalog);
        $this->assertStringNotContainsString('clientPrices()', $catalog);
        $this->assertStringContainsString("DB::table('supplier_product_prices')", $pricing);
        $this->assertStringNotContainsString('$product->supplierPriceBreakpoints()', $pricing);
    }
}
