<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Models\ProductClientPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

class ProductClientPricingTest extends TestCase
{
    public function test_client_override_takes_priority_and_shared_selling_price_is_the_fallback(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'metadata' => [
                'customer_price_breakpoints' => [
                    ['quantity' => 100, 'price' => 2.40],
                    ['quantity' => 1000, 'price' => 2.10],
                ],
                'price_breakpoints' => [['quantity' => 100, 'price' => 0.50]],
            ],
        ]);
        $product->setRelation('clientPrices', new EloquentCollection([
            new ProductClientPrice([
                'client_id' => 11,
                'price_breakpoints' => [
                    ['quantity' => 100, 'price' => 1.85],
                    ['quantity' => 1000, 'price' => 1.60],
                ],
            ]),
            new ProductClientPrice([
                'client_id' => 12,
                'price_breakpoints' => [['quantity' => 100, 'price' => 3.15]],
            ]),
        ]));

        $this->assertSame(1.85, $product->productPriceForQuantity(100, 11));
        $this->assertSame(1.60, $product->productPriceForQuantity(1500, 11));
        $this->assertSame(3.15, $product->productPriceForQuantity(100, 12));
        $this->assertSame(2.40, $product->productPriceForQuantity(100, 99));
        $this->assertSame(2.10, $product->productPriceForQuantity(1500, null));
        $this->assertNull($product->productPriceForQuantity(10, 11));
        $this->assertNull($product->productPriceForQuantity(10, 99));
    }

    public function test_supplier_sync_transmits_only_supplier_prices_and_includes_metadata_for_fallback(): void
    {
        $source = file_get_contents(app_path('Services/SupplierBridge/SyncSupplier.php'));
        $this->assertStringContainsString("'metadata']);", $source);
        $this->assertStringContainsString("'supplier_prices' => \$rows", $source);
        $this->assertStringNotContainsString("'client_prices'", $source);
    }
}
