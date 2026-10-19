<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class CreateOrderProductSearchPrototypeTest extends TestCase
{
    public function test_create_order_catalog_search_is_product_only_and_keeps_the_prototype_structure(): void
    {
        $component = OrderPhase5Source::livewire();
        $view = file_get_contents(resource_path('views/components/catalog/create-product-quantity.blade.php'));
        $catalog = file_get_contents(app_path('Services/ProductCatalogService.php'));
        $model = file_get_contents(app_path('Models/MasterRecord.php'));
        $modalCss = $this->compatibilityCss('flowtrack-master-data.css');

        $this->assertStringContainsString('ProductCatalogService::class', $component);
        $this->assertStringContainsString('->searchForOrderCreation($search, $categoryFilterId ?: null, $resultLimit)', $component);
        $this->assertStringContainsString('->findActiveProductOrFail($productId)', $component);

        // Product catalogue service is an explicit hard boundary: results are
        // master_records.type=product; categories can only be parent metadata.
        $this->assertStringContainsString("->where('master_records.type', 'product')", $catalog);
        $this->assertStringContainsString("->where('type', 'product_category')", $catalog);
        $this->assertStringContainsString("->filter(fn (MasterRecord \$record): bool => \$record->type === 'product')", $catalog);

        $this->assertStringContainsString("@continue(\$product->type !== 'product')", $view);
        $this->assertStringContainsString('<strong>{{ $product->name }}</strong>', $view);
        $this->assertStringContainsString('<x-ui.search-select', $view);
        $this->assertStringContainsString(':options="$productCategories"', $view);
        $this->assertStringContainsString('Product code: {{ $displayCode }}', $view);
        $this->assertStringContainsString('Ref: {{ $referenceCode', $view);
        $this->assertStringContainsString('$detailText = $product->productCatalogSummary();', $view);
        $this->assertStringContainsString('Selected products ({{ count($rows) }})', $view);
        $this->assertStringContainsString('Create new product', $view);
        $this->assertStringContainsString('body:has(.ft-order-create-product-modal) .sidebar.ft-sidebar-template', $modalCss);
        $this->assertStringContainsString('body:has(.ft-order-create-product-modal) .main{grid-column:2', $modalCss);
        $this->assertStringContainsString('public function productCatalogSummary(): ?string', $model);
    }
}
