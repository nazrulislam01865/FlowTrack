<?php

namespace Tests\Unit;

use App\Models\MasterRecord;
use PHPUnit\Framework\TestCase;

class ProductCatalogSummaryTest extends TestCase
{
    public function test_it_removes_a_legacy_category_prefix_from_product_details(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'description' => 'Caps · Embroidery',
        ]);
        $product->setRelation('parent', new MasterRecord(['name' => 'Caps']));

        $this->assertSame('Embroidery', $product->productCatalogSummary());
    }

    public function test_it_hides_a_description_that_is_only_the_category_name(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'description' => 'Backpacks & Bags',
        ]);
        $product->setRelation('parent', new MasterRecord(['name' => 'Backpacks & Bags']));

        $this->assertNull($product->productCatalogSummary());
    }

    public function test_it_keeps_real_product_details_unchanged(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'description' => 'Unisex · 100% Polyester · XS–3XL · 180 GSM',
        ]);
        $product->setRelation('parent', new MasterRecord(['name' => 'Apparel']));

        $this->assertSame(
            'Unisex · 100% Polyester · XS–3XL · 180 GSM',
            $product->productCatalogSummary()
        );
    }
}
