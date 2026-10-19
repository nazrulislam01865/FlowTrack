<?php

namespace Tests\Unit;

use App\Models\MasterRecord;
use Tests\TestCase;

class ProductCustomerSupplierPricingTest extends TestCase
{
    public function test_order_price_uses_customer_tiers_and_supplier_price_uses_existing_tiers(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'metadata' => [
                'price_breakpoints' => [
                    ['quantity' => 100, 'price' => 2.15],
                    ['quantity' => 500, 'price' => 1.9],
                ],
                'customer_price_breakpoints' => [
                    ['quantity' => 100, 'price' => 4.5],
                    ['quantity' => 500, 'price' => 3.75],
                ],
            ],
        ]);

        $this->assertSame(4.5, $product->productPriceForQuantity(100));
        $this->assertSame(4.5, $product->productPriceForQuantity(300));
        $this->assertSame(3.75, $product->productPriceForQuantity(500));
        $this->assertSame(2.15, $product->supplierPriceForQuantity(300));
        $this->assertSame(1.9, $product->supplierPriceForQuantity(500));
        $this->assertNull($product->productPriceForQuantity(50));
        $this->assertNull($product->supplierPriceForQuantity(0));
    }

    public function test_existing_supplier_cost_never_falls_back_into_customer_price(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'metadata' => ['price_breakpoints' => [['quantity' => 1, 'price' => 7.25]]],
        ]);

        $this->assertSame(7.25, $product->supplierPriceForQuantity(1));
        $this->assertSame([], $product->customerPriceBreakpoints());
        $this->assertNull($product->productPriceForQuantity(1));
    }

    public function test_raw_customer_table_fallback_does_not_read_supplier_raw_table(): void
    {
        $product = new MasterRecord([
            'type' => 'product',
            'metadata' => [
                'price_table_raw' => "Quantity\t100\t500\nProduct price\t2.15\t1.90",
                'customer_price_table_raw' => "Quantity\t100\t500\nProduct price\t4.50\t3.75",
            ],
        ]);

        $this->assertSame(3.75, $product->productPriceForQuantity(500));
        $this->assertSame(1.9, $product->supplierPriceForQuantity(500));
    }

    public function test_supplier_delivery_uses_supplier_cost_instead_of_order_selling_price(): void
    {
        $projection = file_get_contents(app_path('Services/SupplierBridge/SupplierOrderProjection.php'));
        $this->assertStringContainsString('supplierPriceForQuantity((int) $item->quantity)', $projection);
        $this->assertStringNotContainsString("'i.unit_price'", $projection);
    }

    public function test_product_editor_exposes_separate_customer_and_supplier_tables(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));

        $this->assertStringContainsString('wire:model.change="productPriceTable"', $form);
        $this->assertStringContainsString('wire:model.change="productCustomerPriceTable"', $form);
        $this->assertStringContainsString("customer_price_breakpoints", $editor);
        $this->assertStringContainsString("'productCustomerPriceTable' =>", $editor);
    }
}
