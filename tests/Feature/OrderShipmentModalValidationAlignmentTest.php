<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderShipmentModalValidationAlignmentTest extends TestCase
{
    public function test_shipment_modal_uses_the_centralized_create_order_address_field_contract(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/add-modal.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/detail/shipment-modal.css'));

        $this->assertStringContainsString('class="ft-ms-modal"', $view);
        $this->assertStringContainsString('ft-ms-shipment-address-form ft-form-standard ft-form-standard--order', $view);
        $this->assertStringNotContainsString('ft-ms-modal__body ft-ms-shipment-address-body ft-create-job-page', $view);
        $this->assertStringNotContainsString('class="ft-ms-modal ft-ms-modal--shipment-address', $view);
        $this->assertStringContainsString('ft-ms-shipment-address-primary-grid ft-create-shipment-primary-grid', $view);
        $this->assertStringContainsString('ft-create-shipment-phone-row', $view);
        $this->assertStringContainsString('ft-create-shipment-phone-code-select', $view);
        $this->assertStringContainsString('ft-create-shipment-saved-address', $view);
        $this->assertStringContainsString('ft-create-shipment-postal-field', $view);
        $this->assertStringContainsString("@error('shipmentForm.recipient')", $view);
        $this->assertStringContainsString("@error('shipmentForm.phone_country_code')", $view);
        $this->assertStringContainsString("@error('shipmentForm.phone')", $view);
        $this->assertStringContainsString("@error('shipmentForm.address')", $view);
        $this->assertStringContainsString("@error('shipmentForm.postal_code')", $view);
        $this->assertStringContainsString('Shipping method', $view);
        $this->assertStringContainsString('mode="modal"', $view);
        $this->assertStringContainsString("@error('shipmentMethod')", $view);
        $this->assertStringContainsString('var(--ft-form-control-height)', $css);
        $this->assertStringContainsString('var(--ft-form-label-size)', $css);
        $this->assertStringContainsString('var(--ft-form-control-size)', $css);
        $this->assertStringNotContainsString('.ft-ms-modal--shipment-address {', $css);
        $this->assertStringNotContainsString('.ft-ms-shipment-address-body {', $css);
    }
}
