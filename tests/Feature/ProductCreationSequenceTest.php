<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class ProductCreationSequenceTest extends TestCase
{
    public function test_order_quick_create_allows_generated_code_and_optional_supplier_while_inquiry_keeps_its_sequence(): void
    {
        $orderView = OrderPhase5Source::createProductsView();
        $inquiryView = file_get_contents(resource_path('views/components/inquiries/create-products.blade.php'));
        $orderComponent = OrderPhase5Source::livewire();
        $inquiryComponent = $this->inquiryLivewireSource();

        foreach ([$orderView, $inquiryView] as $view) {
            $this->assertStringContainsString('ft-product-step-number">1</b> SKU / Product code', $view);
            $this->assertStringContainsString('ft-product-step-number">2</b> Product category', $view);
            $this->assertStringContainsString('@disabled(!$productCodeReady)', $view);
            $this->assertStringContainsString('@disabled(!$productCategoryReady)', $view);
        }

        $this->assertStringContainsString('@disabled(!$productSupplierReady || $hasDuplicateCode)', $orderView);
        $this->assertStringContainsString('ft-product-step-number">4</b> Default supplier', $orderView);
        $this->assertStringContainsString('@disabled(!$productNameReady || $hasDuplicateCode)', $inquiryView);

        $this->assertStringContainsString('private function newProductCodeReadyForCategory(): bool', $orderComponent);
        $this->assertStringContainsString('private function newProductCodeReadyForCategory(): bool', $inquiryComponent);
    }

    public function test_master_product_creation_uses_generated_code_and_shared_taxonomy_fields(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $component = \Tests\Support\AdministrationPhase7Source::masterData();

        $this->assertStringContainsString('Generated automatically after the product is created.', $form);
        $this->assertStringContainsString('wire:model.blur="productReferenceCode"', $form);
        $this->assertSame(5, substr_count($form, '<x-ui.search-select'));
        $this->assertStringContainsString('label="Main category"', $form);
        $this->assertStringContainsString('label="Product category"', $form);
        $this->assertStringContainsString('label="Subcategory"', $form);
        $this->assertStringContainsString('$this->code = $service->nextCode($this->group);', $component);
    }
}
