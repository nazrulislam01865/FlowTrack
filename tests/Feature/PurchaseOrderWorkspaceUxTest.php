<?php

namespace Tests\Feature;

use Tests\TestCase;

class PurchaseOrderWorkspaceUxTest extends TestCase
{
    public function test_po_workspace_has_search_filters_order_identity_and_reuses_the_generator(): void
    {
        $component = file_get_contents(app_path('Livewire/Orders/PurchaseOrders.php'));
        $view = file_get_contents(resource_path('views/livewire/orders/purchase-orders.blade.php'));

        $this->assertStringContainsString("setPoFilter(string \$filter)", $component);
        $this->assertStringContainsString('clearPoFilters()', $component);
        $this->assertStringContainsString('->orWhereHas(\'client\'', $component);
        $this->assertStringContainsString('->orWhereHas(\'items\'', $component);
        $this->assertStringContainsString("'supplierPurchaseOrders as has_active_supplier_po'", $component);
        $this->assertStringContainsString("'first_po_product'", $component);
        $this->assertStringContainsString('->simplePaginate(15', $component);
        $this->assertStringContainsString("'products' => \$service->products(\$order)", $component);
        $this->assertStringContainsString('wire:click="setPoFilter(\'pending\')"', $view);
        $this->assertStringContainsString('wire:click="setPoFilter(\'generated\')"', $view);
        $this->assertStringContainsString('Order products', $view);
        $this->assertStringContainsString("\$product['quantity']", $view);
        $this->assertStringContainsString("\$product['supplier']", $view);
        $this->assertStringContainsString('Open order details', $view);
        $this->assertStringContainsString('ORDER STATUS', $view);
        $this->assertStringContainsString("class=\"ft-po-actual-order-status\"", $view);
        $this->assertStringContainsString('$selectedOrder->status', $view);
        $this->assertStringNotContainsString('Based on the active workflow task', $view);
        $this->assertStringContainsString('supplier-purchase-order-panel', $view);
    }

    public function test_po_action_is_reused_across_order_list_and_po_workspace(): void
    {
        $shared = file_get_contents(resource_path('views/components/orders/po-action.blade.php'));
        $ordersList = file_get_contents(resource_path('views/components/orders/list/po-cell.blade.php'));
        $poList = file_get_contents(resource_path('views/livewire/orders/purchase-orders.blade.php'));

        $this->assertStringContainsString('x-orders.po-action', $ordersList);
        $this->assertStringContainsString('x-orders.po-action', $poList);
        $this->assertStringContainsString("route('orders.purchase-orders'", $shared);
        $this->assertStringContainsString('wire:click="selectOrder(', $shared);
        $this->assertStringContainsString('variant="secondary"', $shared);
        $this->assertStringNotContainsString('showStatus', $shared);
        $this->assertStringContainsString('Purchase order', $ordersList);
        $this->assertStringNotContainsString('Generate PO', $shared);
        $this->assertStringContainsString('View POs', $shared);

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $styles = file_get_contents(public_path('css/flowtrack-po-actions.css'));
        $this->assertStringContainsString('css/flowtrack-po-actions.css', $layout);
        $this->assertStringContainsString('ft-po-row-action-button', $styles);
        $this->assertStringContainsString('ft-theme-text-inverse', $styles);
    }
}
