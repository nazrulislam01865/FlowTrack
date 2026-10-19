<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryMissingProductSupplierResolutionTest extends TestCase
{
    public function test_create_and_detail_inquiry_reuse_the_missing_supplier_resolution_flow(): void
    {
        $createProducts = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryCreateProducts.php'));
        $detailProducts = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryProducts.php'));
        $context = file_get_contents(app_path('Livewire/Inquiries/Concerns/HandlesMissingProductSupplierContext.php'));
        $root = file_get_contents(resource_path('views/livewire/inquiries/index.blade.php'));
        $detail = file_get_contents(resource_path('views/livewire/inquiries/sections/detail.blade.php'));
        $modal = file_get_contents(resource_path('views/components/catalog/missing-product-supplier-modal.blade.php'));

        $this->assertStringContainsString("openMissingProductSupplierModalFor($product, null, 'create_inquiry', true, 'Inquiry')", $createProducts);
        $this->assertStringContainsString('appendCreateInquiryProduct($product)', $createProducts);
        $this->assertStringContainsString('$this->newCreateProductRfqState($product->fresh())', $createProducts);

        $this->assertStringContainsString("openMissingProductSupplierModalFor($product, null, 'inquiry_detail', true, 'Inquiry', 'continue')", $detailProducts);
        $this->assertStringContainsString('openInquiryProductSupplierResolution', $context);
        $this->assertStringContainsString('assertMissingProductSupplierTargetCurrent', $context);
        $this->assertStringContainsString("'create_inquiry'", $context);
        $this->assertStringContainsString("'inquiry_detail'", $context);

        $this->assertStringContainsString('<x-catalog.missing-product-supplier-modal', $root);
        $this->assertStringContainsString('missing-supplier-method="openInquiryProductSupplierResolution"', $detail);
        $this->assertStringContainsString("'submitMode' => 'add'", $modal);
        $this->assertStringContainsString('Create supplier & continue', $modal);
    }
}
