<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderShipmentValidationAlignmentTest extends TestCase
{
    public function test_prototype_required_fields_keep_inline_validation_bindings(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));

        $this->assertStringContainsString('createShipments.$index.contact_name', $row);
        $this->assertStringContainsString('createShipments.$index.phone_country_code', $row);
        $this->assertStringContainsString('createShipments.$index.phone', $row);
        $this->assertStringContainsString('createShipments.$index.address', $row);
        $this->assertStringContainsString('createShipments.$index.postal_code', $row);
        $this->assertStringNotContainsString('createShipments.$index.country', $row);
        $this->assertStringNotContainsString('createShipments.$index.state', $row);
        $this->assertStringNotContainsString('createShipments.$index.city', $row);
    }

    public function test_hidden_structured_location_is_optional_during_create_order(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $shipments = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesCreateOrderShipments.php'));
        $service = file_get_contents(app_path('Services/OrderShipmentService.php'));

        $this->assertStringContainsString("'createShipments.*.country' => ['nullable', 'string', 'max:120']", $creation);
        $this->assertStringContainsString("'country' => ''", $shipments);
        $this->assertStringContainsString('], null, false);', $service);
        $this->assertStringNotContainsString('validateCreateShipmentLocations((array)', $creation);
    }
}
