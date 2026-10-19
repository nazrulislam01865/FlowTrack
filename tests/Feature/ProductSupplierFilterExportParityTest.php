<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductSupplierFilterExportParityTest extends TestCase
{
    public function test_product_supplier_filter_uses_bounded_short_code_lookup(): void
    {
        $page = file_get_contents(resource_path('views/livewire/master-data/sections/product.blade.php'));
        $options = file_get_contents(app_path('Services/FilterOptionService.php'));
        $viewData = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));

        $this->assertStringContainsString('property="productSupplierFilterId"', $page);
        $this->assertStringContainsString('type="suppliers"', $page);
        $this->assertStringContainsString('context="product-list"', $page);
        $this->assertStringContainsString(':infinite-scroll="true"', $page);
        $this->assertStringContainsString(':initial-options="$productSupplierFilterSelectedOptions"', $page);
        $this->assertStringContainsString("$".'this->productSupplierFilterId !==', $viewData);
        $this->assertStringContainsString("'suppliers', 'product-list', [$".'this->productSupplierFilterId]', $viewData);
        $this->assertStringContainsString("$".'context === \'product-list\'', $options);
        $this->assertStringContainsString("orWhereLike('metadata->short_code'", $options);
        $this->assertStringContainsString("->limit($".'limit)', $options);
        $this->assertStringContainsString("canModule('catalog_products', 'view')", $options);
    }

    public function test_catalog_list_and_excel_export_share_all_active_filters(): void
    {
        $filters = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesProductListFilters.php'));
        $pageData = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));
        $catalog = file_get_contents(app_path('Services/MasterDataService.php'));
        $bulk = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesProductBulkActions.php'));

        $this->assertStringContainsString("'supplier_id' => $".'this->productSupplierFilterId', $filters);
        $this->assertStringContainsString("'supplier_id' => $".'this->productSupplierFilterId', $pageData);
        $this->assertStringContainsString("'supplier_state' => $".'this->productSupplierState', $filters);
        $this->assertStringContainsString('updatedProductSupplierFilterId()', $filters);
        $this->assertStringContainsString('clearProductSelection()', $filters);
        $this->assertStringContainsString('resetPage(\'masterPage\')', $filters);
        $this->assertStringContainsString("->where('product_supplier_links.supplier_id', $".'supplierId)', $catalog);
        $this->assertStringContainsString("->orWhereJsonContains('metadata->supplier_ids', $".'supplierId)', $catalog);
        $this->assertStringContainsString('return $this->downloadProducts($this->filteredProductsQuery())', $bulk);
        $this->assertStringContainsString("->query('product', $".'this->search, $this->productFilterValues())', $filters);
    }
}
