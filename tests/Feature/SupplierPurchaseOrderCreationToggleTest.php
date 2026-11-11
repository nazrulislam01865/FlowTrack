<?php

namespace Tests\Feature;

use App\Livewire\Orders\PurchaseOrders;
use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\SupplierPurchaseOrderService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SupplierPurchaseOrderCreationToggleTest extends TestCase
{
    public function test_disabled_toggle_rejects_direct_po_generation_before_any_order_queries(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', false);

        try {
            app(SupplierPurchaseOrderService::class)->generate(new FlowJob(), new User(), 'single', []);
            $this->fail('PO generation must be blocked while disabled.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_disabled_toggle_hides_po_workspace_even_for_a_direct_route_request(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', false);
        $route = app('router')->getRoutes()->getByName('orders.purchase-orders');
        $this->assertNotNull($route);
        $action = $route->getAction('uses');
        $this->assertInstanceOf(\Closure::class, $action);

        try {
            $action();
            $this->fail('The Purchase Orders page must not load while disabled.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_disabled_toggle_blocks_stale_livewire_po_workspace_requests(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', false);

        try {
            app(PurchaseOrders::class)->boot();
            $this->fail('Existing Livewire sessions must not bypass the disabled PO workspace.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_enabled_toggle_restores_the_purchase_orders_page(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', true);
        $route = app('router')->getRoutes()->getByName('orders.purchase-orders');
        $action = $route->getAction('uses');
        $this->assertSame('pages.purchase-orders', $action()->getName());
        app(PurchaseOrders::class)->boot();
    }

    public function test_disabled_toggle_rejects_livewire_po_generation(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', false);

        try {
            app(PurchaseOrders::class)->createSupplierPurchaseOrders();
            $this->fail('The Livewire create action must be blocked while disabled.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_enabling_toggle_restores_generation_entry_point(): void
    {
        config()->set('flowtrack.supplier_po_creation_enabled', true);

        try {
            // Invalid mode is rejected only after the feature flag has allowed access.
            app(SupplierPurchaseOrderService::class)->generate(new FlowJob(), new User(), 'invalid', []);
            $this->fail('Invalid PO mode must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_visibility_switch_preserves_existing_po_documents_and_service_actions(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));
        $detail = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $action = file_get_contents(resource_path('views/components/orders/po-action.blade.php'));
        $page = file_get_contents(resource_path('views/livewire/orders/purchase-orders.blade.php'));
        $workspace = file_get_contents(app_path('Livewire/Orders/PurchaseOrders.php'));

        $this->assertStringContainsString("@if(config('flowtrack.supplier_po_creation_enabled', false))", $sidebar);
        $this->assertStringNotContainsString('>Generate PO</x-ui.button>', $detail);
        $this->assertStringContainsString("@if(config('flowtrack.supplier_po_creation_enabled', false) && \$generated)", $action);
        $this->assertStringContainsString('@if(!$poRows)', $page);
        $this->assertStringContainsString('public function sendSupplierPurchaseOrder(int $poId)', $workspace);
        $this->assertStringContainsString('public function sendAllSupplierPurchaseOrders()', $workspace);
    }
}
