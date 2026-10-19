<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Support\CreateOrderShippingMethodPresenter;
use Tests\TestCase;

class CreateOrderShippingMethodPrototypeTest extends TestCase
{
    public function test_create_order_uses_the_shipping_method_prototype_component(): void
    {
        $create = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $picker = file_get_contents(resource_path('views/components/jobs/create/shipping-method-picker.blade.php'));

        $this->assertStringContainsString('<x-jobs.create.shipping-method-picker', $create);
        $this->assertStringNotContainsString('Select order shipment urgency', $create);
        $this->assertStringContainsString('Shipping method', $picker);
        $this->assertStringContainsString('Select shipping method', $picker);
        $this->assertStringContainsString('STANDARD EXPRESS SHIPPING', $picker);
        $this->assertStringContainsString('selectCreateShippingMethod', $picker);
        $this->assertStringContainsString('ft-create-shipping-selected-card', $picker);
        $this->assertStringContainsString('selectedCard(', $picker);
        $this->assertStringContainsString('ft-order-required-star', $picker);
        $this->assertStringContainsString('Applied automatically to every shipment address.', $create);
    }

    public function test_presenter_matches_the_approved_shipping_copy(): void
    {
        $sea = new MasterRecord(['code' => 'SEA', 'name' => 'Sea Freight']);
        $air = new MasterRecord(['code' => 'AIR', 'name' => 'Air Freight']);
        $express = new MasterRecord(['code' => 'EXP', 'name' => 'Express Courier']);
        $urgent = new MasterRecord(['id' => 51, 'name' => 'Urgent']);
        $super = new MasterRecord(['id' => 52, 'name' => 'Super Urgent']);

        $this->assertSame('Sea Shipping', CreateOrderShippingMethodPresenter::methodLabel($sea));
        $this->assertSame('About 1 month', CreateOrderShippingMethodPresenter::methodEstimate($sea));
        $this->assertSame('Air Shipping', CreateOrderShippingMethodPresenter::methodLabel($air));
        $this->assertSame('About 10–15 days', CreateOrderShippingMethodPresenter::methodEstimate($air));
        $this->assertSame('express', CreateOrderShippingMethodPresenter::methodKind($express));

        $urgencies = CreateOrderShippingMethodPresenter::expressUrgencies(collect([$urgent, $super]));
        $this->assertSame(['Normal', 'Urgent', 'Super Urgent'], $urgencies->pluck('name')->all());
        $this->assertSame(['About 7 days', 'About 3 days', 'About 1–2 days'], $urgencies->pluck('estimate')->all());
    }

    public function test_create_order_keeps_legacy_array_storage_but_allows_only_one_shipping_method(): void
    {
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));
        $model = file_get_contents(app_path('Models/FlowJob.php'));
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $migration = file_get_contents(database_path('migrations/2026_09_04_131500_add_shipment_method_ids_to_flow_jobs.php'));

        $this->assertStringContainsString('public array $shipmentMethodIds = [];', $index);
        $this->assertStringContainsString("'shipmentMethodIds' => ['array', 'max:1']", $creation);
        $this->assertStringContainsString('setCreateShippingSelectionForAllShipments(', $creation);
        $this->assertStringContainsString("'shipmentMethodIds' => ['required', 'array', 'size:1']", $creation);
        $shipments = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesCreateOrderShipments.php'));
        $this->assertStringContainsString('syncCreateShipmentMethodsFromGlobalSelection', $shipments);
        $this->assertStringContainsString("'shipment_method_id' => $selectedMethodId ?: null", $shipments);
        $this->assertStringContainsString("'shipment_method_ids' => self::singlePositiveId", $dto);
        $this->assertStringContainsString("->where('type', 'shipment_method')", $creation);
        $this->assertStringContainsString("'shipment_method_ids' => 'array'", $model);
        $this->assertStringContainsString("'shipment_method_ids' => array_values(array_map('intval', (array) (\$data['shipment_method_ids'] ?? [])))", $service);
        $this->assertStringContainsString("\$table->json('shipment_method_ids')->nullable();", $migration);
    }
}
