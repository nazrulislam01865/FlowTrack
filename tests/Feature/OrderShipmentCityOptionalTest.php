<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderShipmentCityOptionalTest extends TestCase
{
    public function test_structured_city_remains_optional_but_is_not_exposed_in_compact_address_forms(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $shipmentService = file_get_contents(app_path('Services/OrderShipmentService.php'));
        $createRow = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));
        $shipmentModal = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/add-modal.blade.php'));

        $this->assertStringContainsString("'createShipments.*.city' => ['nullable', 'string', 'max:120']", $creation);
        $this->assertStringNotContainsString("'createShipments.*.city.required'", $creation);
        $this->assertStringNotContainsString('createShipments.{{ $index }}.city', $createRow);

        $this->assertStringNotContainsString("'city' => 'City is required.'", $shipmentService);
        $this->assertStringNotContainsString('shipmentForm.city', $shipmentModal);
        $this->assertStringContainsString('validatedAddressFields($payload, null, false, true)', $shipmentService);
    }
}
