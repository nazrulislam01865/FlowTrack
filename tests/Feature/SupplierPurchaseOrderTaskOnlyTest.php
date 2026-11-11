<?php

namespace Tests\Feature;

use App\Livewire\Orders\PurchaseOrders;
use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\SupplierPurchaseOrderService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SupplierPurchaseOrderTaskOnlyTest extends TestCase
{
    public function test_po_workspace_cannot_generate_a_po_even_with_direct_livewire_method(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', true);
        $this->expectException(HttpException::class);
        app(PurchaseOrders::class)->createSupplierPurchaseOrders();
    }

    public function test_direct_non_task_generation_is_denied_before_database_work(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', true);
        try {
            app(SupplierPurchaseOrderService::class)->generate(new FlowJob(), new User(), 'single', []);
            $this->fail('An order-row or workspace action must not generate a PO.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_order_list_only_offers_view_for_existing_purchase_orders(): void
    {
        $component = file_get_contents(resource_path('views/components/orders/po-action.blade.php'));
        $header = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $workspace = file_get_contents(resource_path('views/livewire/orders/purchase-orders.blade.php'));
        $task = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));

        $this->assertStringContainsString('&& $generated', $component);
        $this->assertStringContainsString('View POs', $component);
        $this->assertStringNotContainsString('Generate PO', $component);
        $this->assertStringNotContainsString('>Generate PO</x-ui.button>', $header);
        $this->assertStringContainsString('@if(!$poRows && !$create)', $workspace);
        $this->assertStringContainsString('createOptionalSupplierPo(', $task);
        $this->assertStringContainsString('skipOptionalSupplierPo(', $task);
        $this->assertStringContainsString("'create' => 1", $workflow);
        $this->assertStringContainsString('artworkTaskAction: true', file_get_contents(app_path('Livewire/Orders/PurchaseOrders.php')));
    }
}
