<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductShipmentUrgencyOptionsImplementationTest extends TestCase
{
    public function test_product_shipping_urgencies_are_master_data_backed_and_reusable(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $model = file_get_contents(app_path('Models/MasterRecord.php'));
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $shipping = file_get_contents(resource_path('views/components/catalog/product-shipment-urgencies.blade.php'));
        $detail = file_get_contents(resource_path('views/components/catalog/product-view.blade.php'));

        $this->assertStringContainsString('public array $productShipmentUrgencies = [];', $component);
        $this->assertStringContainsString('addProductShipmentUrgency', $component);
        $this->assertStringContainsString('openProductShipmentUrgencyPicker', $component);
        $this->assertStringContainsString('toggleProductShipmentUrgencyPickerSelection', $component);
        $this->assertStringContainsString('confirmProductShipmentUrgencies', $component);
        $this->assertStringContainsString("ofType('shipment_urgency')", $component);
        $this->assertStringContainsString('$metadata[\'shipment_urgency_options\']', $component);
        $this->assertStringContainsString('productShipmentUrgencyOptions()', $model);
        $this->assertStringContainsString('<x-catalog.product-shipment-urgencies', $form);
        $this->assertStringContainsString('ft-product-shipping-picker-card', $shipping);
        $this->assertStringContainsString('Add selected', $shipping);
        $this->assertStringNotContainsString('<x-ui.select-filter', $shipping);
        $this->assertStringContainsString('Shipment Urgencies in Master Data', $shipping);
        $this->assertStringContainsString('product-specific extra charge', $shipping);
        $this->assertStringContainsString('productShipmentUrgencyOptions()', $detail);
        $this->assertStringContainsString('ft-product-options-shipping-grid', $detail);
        $this->assertStringContainsString('ft-product-shipping-detail-table', $detail);
        $this->assertStringNotContainsString("ofType('shipment_method')", $shipping);
    }
}
