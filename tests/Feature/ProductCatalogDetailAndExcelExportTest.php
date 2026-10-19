<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCatalogDetailAndExcelExportTest extends TestCase
{
    public function test_product_details_display_all_tagged_suppliers_and_the_default(): void
    {
        $view = file_get_contents(resource_path('views/components/catalog/product-view.blade.php'));
        $page = file_get_contents(resource_path('views/livewire/master-data/sections/product.blade.php'));
        $source = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));
        $lookup = file_get_contents(app_path('Services/ProductSupplierLookup.php'));

        $this->assertStringContainsString(':suppliers="$viewProductSuppliers"', $page);
        $this->assertStringContainsString('@forelse($suppliers as $supplier)', $view);
        $this->assertStringContainsString('$supplier->supplierShortCode()', $view);
        $this->assertStringContainsString('$supplier->name', $view);
        $this->assertStringContainsString('No suppliers tagged', $view);
        $this->assertStringContainsString('ProductSupplierLookup::class', $source);
        $this->assertStringContainsString('productSupplierIds()', $lookup);
        $this->assertStringContainsString("DB::table('product_supplier_links')", $lookup);
        $this->assertStringContainsString("->where('workspace_id', \$workspaceId)", $lookup);
        $this->assertStringContainsString("->ofType('supplier')", $lookup);
    }

    public function test_product_list_and_selected_export_produce_full_detail_xlsx(): void
    {
        $page = file_get_contents(resource_path('views/livewire/master-data/sections/product.blade.php'));
        $bulk = file_get_contents(resource_path('views/components/catalog/bulk-actions.blade.php'));
        $actions = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesProductBulkActions.php'));
        $writer = file_get_contents(app_path('Services/ProductCatalogExportService.php'));

        $this->assertStringContainsString('wire:click="exportProducts"', $page);
        $this->assertStringContainsString('wire:click="exportSelectedProducts"', $bulk);
        $this->assertStringContainsString("canModule('catalog_products', 'view')", $actions);
        $this->assertStringContainsString('filteredProductsQuery()', $actions);
        $this->assertStringContainsString('selectedProductsQuery()', $actions);
        $this->assertStringContainsString('.xlsx', $actions);
        $this->assertStringContainsString('ProductSupplierLookup::class', $writer);
        $this->assertStringContainsString('chunkById(200', $writer);
        $this->assertStringContainsString('Supplier price by quantity', $writer);
        $this->assertStringContainsString('Customer price by quantity', $writer);
        $this->assertStringContainsString('Shipping urgencies', $writer);
        $this->assertStringContainsString('Certificate URL', $writer);
        $this->assertStringContainsString('DataType::TYPE_STRING', $writer);
        $this->assertStringContainsString('new Xlsx($book)', $writer);
    }
}
