<?php

namespace Tests\Unit;

use App\Services\SupplierBridge\SupplierCurrencyPriceTables;
use App\Services\SupplierBridge\SupplierPriceMatrix;
use App\Services\SupplierBridge\SupplierPricing;
use Tests\TestCase;

final class SupplierCurrencyPriceTablesTest extends TestCase
{
    public function test_independent_currency_matrix_versions_include_tiers_and_charges(): void
    {
        $pricing = new SupplierCurrencyPriceTables(new SupplierPricing(), new SupplierPriceMatrix());
        $prices = [['quantity' => 100, 'price' => 7.5]];
        $this->assertSame(64, strlen($pricing->version([], [])));
        $this->assertNotSame($pricing->version([], []), $pricing->version($prices, []));
        $this->assertNotSame($pricing->version($prices, []), $pricing->version($prices, [
            ['quantity' => 100, 'standard_shipment' => 2.0],
        ]));
        $this->assertSame($pricing->version($prices, []), $pricing->version([['quantity' => '100', 'price' => '7.5']], []));
    }

    public function test_extra_currency_storage_does_not_touch_approved_supplier_pricing(): void
    {
        $service = (string) file_get_contents(app_path('Services/SupplierBridge/SupplierCurrencyPriceTables.php'));
        $migration = (string) file_get_contents(database_path('migrations/2026_11_17_190000_create_supplier_product_currency_tables.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/SupplierBridge/SupplierNativeProductApi.php'));
        $this->assertStringContainsString("supplier_product_currency_tables", $service);
        $this->assertStringNotContainsString("DB::table('supplier_product_prices')", $service);
        $this->assertStringContainsString('assigned($locked, $supplierId)', $service);
        $this->assertStringContainsString("['product_id', 'supplier_id', 'currency']", $migration);
        $this->assertStringContainsString("'supplier_currency_table' => 'supplier_pricing'", $controller);
    }
}
