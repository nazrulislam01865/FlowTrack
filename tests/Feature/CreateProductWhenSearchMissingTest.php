<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class CreateProductWhenSearchMissingTest extends TestCase
{
    public function test_inquiry_and_order_only_offer_search_create_when_no_product_matches(): void
    {
        $sharedView = file_get_contents(resource_path('views/components/catalog/create-product-quantity.blade.php'));
        $inquiryView = file_get_contents(resource_path('views/components/inquiries/create-products.blade.php'));
        $orderView = OrderPhase5Source::createProductsView();
        $inquiryComponent = $this->inquiryLivewireSource();
        $orderComponent = OrderPhase5Source::livewire();

        $this->assertStringContainsString("\$showCreateProductSuggestion = \$productSearchValue !== '' && (int) \$productResultTotal === 0;", $sharedView);
        $this->assertStringContainsString('@if($showCreateProductSuggestion && $canCreateCatalogProduct)', $sharedView);
        $this->assertStringContainsString('wire:click="openCreateOrderProductModalFromSearch"', $sharedView);
        $this->assertStringContainsString('No matching product found.', $sharedView);
        $this->assertStringContainsString('<x-catalog.create-product-quantity', $inquiryView);
        $this->assertStringContainsString('<x-catalog.create-product-quantity', $orderView);

        foreach ([$inquiryComponent, $orderComponent] as $component) {
            $this->assertStringContainsString('public function openCreateOrderProductModalFromSearch(): void', $component);
            $this->assertStringContainsString('$searchedName = trim($this->createProductSearch);', $component);
            $this->assertStringContainsString('$this->newProductName = $searchedName;', $component);
        }
    }
}
