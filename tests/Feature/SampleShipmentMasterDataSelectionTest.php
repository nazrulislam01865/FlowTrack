<?php

namespace Tests\Feature;

use App\Support\CreateOrderShippingMethodPresenter as Presenter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SampleShipmentMasterDataSelectionTest extends TestCase
{
    private function methods(): Collection
    {
        return collect([
            (object) ['id' => 12, 'code' => 'SEA', 'name' => 'Sea Shipping'],
            (object) ['id' => 13, 'code' => 'AIR', 'name' => 'Air Shipping'],
            (object) ['id' => 14, 'code' => 'ROAD', 'name' => 'Road Freight'],
            (object) ['id' => 15, 'code' => 'EXP', 'name' => 'Standard Express Shipping'],
        ]);
    }

    public function test_sample_shipping_uses_same_master_data_labels_and_icons_as_create_order(): void
    {
        $methods = $this->methods();
        $this->assertSame(['Sea Shipping', 'Air Shipping', 'Road Freight'],
            Presenter::directMethods($methods)->map(fn ($m) => Presenter::methodLabel($m))->all());
        $this->assertSame('sea', Presenter::methodKind($methods[0]));
        $this->assertSame('air', Presenter::methodKind($methods[1]));
        $this->assertSame('road', Presenter::methodKind($methods[2]));
        $this->assertSame('express', Presenter::methodKind($methods[3]));
    }

    public function test_express_shipping_keeps_real_urgency_ids_and_virtual_normal_level(): void
    {
        $methods = $this->methods();
        $urgencies = collect([
            (object) ['id' => 81, 'name' => 'Urgent'],
            (object) ['id' => 82, 'name' => 'Super Urgent'],
        ]);
        $this->assertSame('Standard Express Shipping — Normal',
            Presenter::selectedCard($methods, $urgencies, [15], [])['title']);
        $this->assertSame('Standard Express Shipping — Urgent',
            Presenter::selectedCard($methods, $urgencies, [15], [81])['title']);
        $this->assertSame('Standard Express Shipping — Super Urgent',
            Presenter::selectedCard($methods, $urgencies, [15], [82])['title']);
    }

    public function test_sample_modal_reuses_existing_picker_and_server_validates_master_data(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $picker = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/method-picker.blade.php'));
        $service = file_get_contents(app_path('Services/Orders/SampleWorkflowService.php'));

        $this->assertStringContainsString('shipment.method-picker', $modal);
        $this->assertStringContainsString('mode="sample"', $modal);
        $this->assertStringContainsString('selectSampleShippingMethod', $picker);
        $this->assertStringContainsString("active('shipment_method')", $service);
        $this->assertStringContainsString("active('shipment_urgency')", $service);
        $this->assertStringNotContainsString('<option value="Courier / Express">', $modal);
    }
}
