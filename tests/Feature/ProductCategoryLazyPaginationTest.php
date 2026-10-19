<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCategoryLazyPaginationTest extends TestCase
{
    public function test_product_category_hierarchy_matches_prototype_pagination_and_lazy_batches(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $view = file_get_contents(resource_path('views/components/catalog/category-list.blade.php'));

        $this->assertStringContainsString('public int $categoryPerPage = 6;', $component);
        $this->assertStringContainsString('private const CATEGORY_PRODUCT_BATCH = 4;', $component);
        $this->assertStringContainsString('private const CATEGORY_SUBCATEGORY_BATCH = 3;', $component);
        $this->assertStringContainsString('in_array($value, [6, 10, 20, 50], true)', $component);
        $this->assertStringContainsString('public array $categoryProductLimits = [];', $component);
        $this->assertStringContainsString('public array $categorySubcategoryLimits = [];', $component);
        $this->assertStringContainsString('function loadMoreCategoryProducts', $component);
        $this->assertStringContainsString('function loadMoreCategorySubcategories', $component);
        $this->assertStringContainsString('->paginate(', $component);
        $this->assertStringContainsString('max(1, $this->categoryPerPage)', $component);
        $this->assertStringContainsString('->limit($limit)', $component);

        $this->assertStringContainsString('Load 4 more', $view);
        $this->assertStringContainsString('Load 3 more', $view);
        $this->assertStringContainsString('@foreach([6,10,20,50] as $size)', $view);
        $this->assertStringContainsString('aria-label="First page">|‹</button>', $view);
        $this->assertStringContainsString('aria-label="Last page">›|</button>', $view);
        $this->assertStringContainsString('Showing {{ number_format($loadedMainChildren) }} of {{ number_format($mainChildTotal) }} product categories', $view);
        $this->assertStringContainsString('Showing {{ number_format($loadedSubcategories) }} of {{ number_format($subcategoryTotal) }} subcategories', $view);
    }

    public function test_category_table_does_not_preload_product_or_subcategory_rows(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $navigation = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterNavigation.php'));

        $this->assertStringNotContainsString('$categoryProductIndex =', $component);
        $this->assertStringContainsString('Product Category rows are the first true lazy level', $component);
        $this->assertStringContainsString('Subcategory rows are the second true lazy level', $component);

        $loadStart = strpos($navigation, 'public function loadMasterRecords(): void');
        $loadEnd = strpos($navigation, 'private function authorizeGroupAction', $loadStart);
        $loadMethod = substr($navigation, $loadStart, $loadEnd - $loadStart);

        $this->assertStringNotContainsString('synchronizeLegacyTaxonomy()', $loadMethod);
        $this->assertStringNotContainsString('productCategories()->pluck', $loadMethod);
        $this->assertStringNotContainsString('subcategories()', $loadMethod);
    }
}
