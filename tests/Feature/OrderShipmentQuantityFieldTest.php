<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderShipmentQuantityFieldTest extends TestCase
{
    public function test_create_order_shipment_address_prototype_does_not_expose_quantity(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));

        $this->assertStringNotContainsString('Quantity <em>Optional</em>', $row);
        $this->assertStringNotContainsString('createShipments.{{ $index }}.quantity', $row);

        $this->assertStringContainsString("'createShipments.*.quantity' => ['nullable', 'integer', 'min:1'", $creation);
        $this->assertStringContainsString("'quantity' => filled(\$row['quantity']", $dto);
    }

    public function test_shipment_stage_compact_address_form_preserves_quantity_without_exposing_it(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/add-modal.blade.php'));
        $manager = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderShipments.php'));
        $plan = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/plan-table.blade.php'));
        $details = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/details-modal.blade.php'));

        $this->assertStringNotContainsString('QUANTITY (OPTIONAL)', $modal);
        $this->assertStringNotContainsString('wire:model.defer="shipmentForm.quantity"', $modal);
        $this->assertStringContainsString("'quantity' => null", $manager);
        $this->assertStringContainsString("'quantity' => \$shipment->quantity", $manager);
        $this->assertStringContainsString('<th>Quantity</th>', $plan);
        $this->assertStringContainsString('<dt>Quantity</dt>', $details);
    }

    public function test_quantity_is_persisted_as_nullable_positive_integer(): void
    {
        $model = file_get_contents(app_path('Models/OrderShipment.php'));
        $service = file_get_contents(app_path('Services/OrderShipmentService.php'));
        $presenter = file_get_contents(app_path('Support/OrderShipmentPresenter.php'));
        $migration = file_get_contents(database_path('migrations/2026_09_04_221500_add_quantity_to_order_shipments_table.php'));

        $this->assertStringContainsString("'quantity',", $model);
        $this->assertStringContainsString("'quantity' => 'integer'", $model);
        $this->assertStringContainsString("unsignedInteger('quantity')->nullable()", $migration);
        $this->assertStringContainsString('validatedQuantity', $service);
        $this->assertStringContainsString("'quantity' => \$shipment->quantity !== null", $presenter);
    }
}
