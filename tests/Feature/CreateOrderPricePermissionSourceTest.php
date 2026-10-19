<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Guard customer/supplier separation without requiring seeded supplier-price fixtures. */
class CreateOrderPricePermissionSourceTest extends TestCase
{
    public function test_order_creation_persists_distinct_authoritative_prices(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $pricing = file_get_contents(app_path('Services/Orders/OrderProductPricing.php'));
        $model = file_get_contents(app_path('Models/FlowJobItem.php'));
        $legacy = file_get_contents(app_path('Services/LegacyJobService.php'));

        $this->assertStringContainsString('productPriceForQuantity($quantity, (int) $data[\'clientId\'])', $creation);
        $this->assertStringContainsString('supplierUnitPrices($data[\'jobItems\'], $products)', $creation);
        $this->assertStringContainsString('unset($data[\'jobItems\'][$index][\'supplier_price\'])', $creation);
        $this->assertStringContainsString("DB::table('supplier_product_prices')", $pricing);
        $this->assertStringContainsString("DB::table('product_supplier_links')", $pricing);
        $this->assertStringNotContainsString('productPriceForQuantity(', $pricing);
        $this->assertStringContainsString("'supplier_unit_price'", $model);
        $this->assertStringContainsString("'remote_area_surcharge'", $model);
        $this->assertStringContainsString("'supplier_unit_price' => isset(\$item['supplier_unit_price'])", $legacy);
    }

    public function test_supplier_costs_are_protected_and_remote_price_is_optional(): void
    {
        $pricing = file_get_contents(app_path('Services/Orders/SupplierCostVisibility.php'));
        $view = file_get_contents(resource_path('views/components/catalog/create-product-card.blade.php'));
        $master = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));
        $export = file_get_contents(app_path('Services/ProductCatalogExportService.php'));

        $this->assertStringContainsString("'supplier_pricing', 'view'", $pricing);
        $this->assertStringContainsString('@if($canViewSupplierPrices)', $view);
        $this->assertStringContainsString('ft-pq-masked-price', $view);
        $this->assertStringNotContainsString('remote_area_surcharge', $view);
        $this->assertStringContainsString('grid-area: cost', file_get_contents(resource_path('css/modules/orders/create-products.css')));
        $this->assertStringContainsString('productRemoteSurchargeTable', $master);
        $this->assertStringContainsString("unset(\$metadata['price_table_raw'], \$metadata['price_breakpoints'])", $master);
        $this->assertStringContainsString('$canViewSupplierPrices ?', $export);
    }
}
