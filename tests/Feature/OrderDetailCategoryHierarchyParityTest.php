<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailCategoryHierarchyParityTest extends TestCase
{
    public function test_order_detail_uses_product_master_category_hierarchy_like_inquiry_detail(): void
    {
        $root = base_path();
        $orderView = file_get_contents($root.'/resources/views/components/jobs/order-detail/products.blade.php');
        $service = file_get_contents($root.'/app/Services/LegacyJobService.php');

        self::assertStringContainsString('productMainCategory()', $orderView);
        self::assertStringContainsString('productClassificationPath()', $orderView);
        self::assertStringContainsString('$categoryDisplay', $orderView);
        self::assertStringNotContainsString('$item->category_name ?: ($catalog', $orderView);

        self::assertStringContainsString('items.catalogProduct.parent:id,name,code,type,status,metadata', $service);
        self::assertStringContainsString('$missingCatalogItems', $service);
        self::assertStringContainsString("setRelation('catalogProduct', \$matched)", $service);
    }
}
