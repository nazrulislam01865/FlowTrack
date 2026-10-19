<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductTaxonomySelectorSyncTest extends TestCase
{
    public function test_product_form_taxonomy_selectors_use_server_authoritative_action(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $select = file_get_contents(resource_path('views/components/ui/search-select.blade.php'));
        $component = \Tests\Support\AdministrationPhase7Source::masterData();

        $this->assertSame(3, substr_count($form, 'action="setProductTaxonomySelection"'));
        $this->assertStringContainsString("'action' => null", $select);
        $this->assertStringContainsString('$wire.call(@js($action)', $select);
        $this->assertStringContainsString('public function setProductTaxonomySelection(string $property, string $value): void', $component);
    }

    public function test_product_form_reads_children_from_canonical_product_taxonomy(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::masterData();

        $this->assertStringContainsString('$canonicalMainCategories = $productTaxonomy ? $productTaxonomy->mainCategories(true) : collect();', $component);
        $this->assertStringContainsString('$canonicalProductCategories = $productTaxonomy ? $productTaxonomy->productCategories(true) : collect();', $component);
        $this->assertStringContainsString('$main = $productTaxonomy?->mainCategoryFor($category);', $component);
        $this->assertStringContainsString('$productTaxonomy->subcategories(true)', $component);
        $this->assertStringContainsString('Select a product category that belongs to the selected main category.', $component);
        $this->assertStringContainsString('Select a subcategory that belongs to the selected product category.', $component);
    }
}
