<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailsAddProductSupplierFallbackTest extends TestCase
{
    public function test_order_details_can_create_link_or_skip_a_missing_product_supplier_from_the_shared_modal(): void
    {
        $products = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderProducts.php'));
        $context = file_get_contents(app_path('Livewire/Jobs/Concerns/HandlesMissingProductSupplierContext.php'));
        $picker = file_get_contents(resource_path('views/components/catalog/detail-add-product.blade.php'));
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/products.blade.php'));

        $this->assertStringContainsString('$linkedSupplier = app(\App\Services\ProductCatalogService::class)->supplierForProduct($product);', $products);
        $this->assertStringContainsString("openMissingProductSupplierModalFor($product, null, 'order_detail', true, 'Order', 'continue')", $products);
        $this->assertStringContainsString('openJobProductSupplierResolution', $context);
        $this->assertStringContainsString('assertMissingProductSupplierTargetCurrent', $context);
        $this->assertStringContainsString("authorizeMissingProductSupplierContext('order_detail')", $context);
        $this->assertStringContainsString('$this->jobProductSupplierId = (int) $supplier->id;', $context);
        $this->assertStringContainsString('$this->jobProductSupplierLabel = (string) $supplier->name;', $context);
        $this->assertStringContainsString('$this->jobProductSupplierSkipped = true;', $context);
        $this->assertStringContainsString("Rule::requiredIf(fn (): bool => ! $this->jobProductSupplierSkipped)", $products);

        $this->assertStringContainsString("'missingSupplierMethod' => null", $picker);
        $this->assertStringContainsString("'supplierSkipped' => false", $picker);
        $this->assertStringContainsString('Supplier skipped for now', $picker);
        $this->assertStringContainsString('Create supplier', $picker);
        $this->assertStringContainsString('missing-supplier-method="openJobProductSupplierResolution"', $view);
        $this->assertStringContainsString('action="updateAddJobProductSupplierFromSelector"', $picker);
    }
}
