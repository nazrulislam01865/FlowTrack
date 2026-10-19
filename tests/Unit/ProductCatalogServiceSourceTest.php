<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductCatalogServiceSourceTest extends TestCase
{
    public function test_catalog_service_hard_filters_product_records(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Services/ProductCatalogService.php');

        self::assertStringContainsString("->where('master_records.type', 'product')", $source);
        self::assertStringContainsString("->where('master_records.status', 'active')", $source);
        self::assertStringContainsString("->where('type', 'product_category')", $source);
        self::assertStringContainsString("\$record->type === 'product'", $source);
    }
}
