<?php

namespace Tests\Feature;

use App\Services\OrderWorkflowSetupService;
use Tests\TestCase;

class SupplierPurchaseOrderWorkflowTest extends TestCase
{
    public function test_artwork_workflow_has_optional_supplier_po_task_and_customer_po_upload_remains(): void
    {
        $stages = OrderWorkflowSetupService::fixedStages();
        $artwork = collect($stages)->firstWhere('key', 'art');
        $this->assertNotNull($artwork);
        $keys = array_column($artwork['tasks'], 'automation_key');
        $this->assertNotContains('ART_CREATE_SUPPLIER_PO', $keys);
        $this->assertContains('ART_SUPPLIER_PO_OPTIONAL', $keys);
        $this->assertContains('ART_INTERNAL_REVIEW', $keys);
        $this->assertContains('ART_SEND_ORDER_TEAM', $keys);
        $newOrder = collect($stages)->firstWhere('key', 'new');
        $this->assertContains('NEW_UPLOAD_PO', array_column($newOrder['tasks'], 'automation_key'));
    }

    public function test_po_page_is_under_orders_and_linked_from_sidebar_details_and_each_list_stage(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));
        $detail = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $list = file_get_contents(resource_path('views/components/orders/list/table.blade.php'));
        $headers = file_get_contents(resource_path('views/components/orders/prototype-list.blade.php'));
        $poCell = file_get_contents(resource_path('views/components/orders/list/po-cell.blade.php'));
        $listHost = file_get_contents(app_path('Livewire/Orders/Index.php'));

        $this->assertStringContainsString("name('orders.purchase-orders')", $routes);
        $this->assertStringContainsString("permission:jobs.view", $routes);
        $this->assertStringContainsString('route="orders.purchase-orders"', $sidebar);
        $this->assertStringNotContainsString('>Generate PO</x-ui.button>', $detail);
        $this->assertSame(8, substr_count($list, "@include('components.orders.list.po-cell'"));
        $this->assertStringContainsString("\$headers[] = 'Purchase order';", $headers);
        $this->assertStringContainsString("'supplierPurchaseOrders as has_active_supplier_po'", $listHost);
        $this->assertStringContainsString('x-orders.po-action', $poCell);
        $this->assertStringContainsString("route('orders.purchase-orders'", file_get_contents(resource_path('views/components/orders/po-action.blade.php')));
        $this->assertStringContainsString('has_active_supplier_po', $poCell);
    }

    public function test_standalone_page_uses_existing_supplier_po_panel_and_actions(): void
    {
        $page = file_get_contents(app_path('Livewire/Orders/PurchaseOrders.php'));
        $view = file_get_contents(resource_path('views/livewire/orders/purchase-orders.blade.php'));
        $panel = file_get_contents(resource_path('views/components/jobs/order-detail/supplier-purchase-order-panel.blade.php'));
        $this->assertStringContainsString('OrderReadService::class', $page);
        $this->assertStringContainsString('canEditVisibleJob', $page);
        $this->assertStringContainsString('createSupplierPurchaseOrders()', $page);
        $this->assertStringContainsString('sendSupplierPurchaseOrder(int $poId)', $page);
        $this->assertStringContainsString('sendAllSupplierPurchaseOrders()', $page);
        $this->assertStringContainsString('completeSupplierPurchaseOrdersWithoutEmail()', $page);
        $this->assertStringContainsString('selectSupplierPurchaseOrder(', $panel);
        $this->assertStringContainsString('chooseSupplierPurchaseOrderMode(', $panel);
        $this->assertStringContainsString('addSupplierPoRecipient(', $panel);
        $this->assertStringContainsString('removeSupplierPoRecipient(', $panel);
        $this->assertStringContainsString(':standalone="true"', $view);
        $this->assertStringContainsString('supplier-purchase-order-panel', $view);
        $this->assertStringNotContainsString('ManagesSupplierPurchaseOrders', $page);
    }

    public function test_po_service_is_order_bound_with_atomic_generation_and_no_customer_price(): void
    {
        $service = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderService.php'));
        $migration = file_get_contents(database_path('migrations/2026_11_04_000001_move_supplier_po_from_artwork_to_orders.php'));

        $this->assertStringContainsString('generate(FlowJob $job', $service);
        $this->assertStringContainsString('rows(FlowJob $job)', $service);
        $this->assertStringContainsString('send(FlowJob $job', $service);
        $this->assertStringContainsString("->where('flow_job_id', \$job->id)", $service);
        $this->assertStringContainsString("->where('flow_job_id', \$job->id)->orderByDesc('id')->first(['po_number', 'archived_at'])", $service);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString('DB::transaction(', $service);
        $this->assertStringContainsString("'supplier_unit_cost' => \$item->supplier_unit_price", $service);
        $this->assertStringNotContainsString('$item->unit_price', $service);
        $this->assertStringContainsString('EmailMessage::storageAttachment(', $service);
        $this->assertStringContainsString("'task_id' => null", $service);
        $this->assertStringContainsString("where('automation_key', 'ART_CREATE_SUPPLIER_PO')", $migration);
        $this->assertStringContainsString("->whereNull('deleted_at')", $migration);
        $this->assertStringContainsString('supplier_po_order_active_idx', $migration);
    }

    public function test_existing_po_records_and_revision_history_are_preserved_without_workflow_completion(): void
    {
        $service = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderService.php'));
        $workflow = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $page = file_get_contents(app_path('Livewire/Orders/PurchaseOrders.php'));
        $documents = file_get_contents(app_path('Services/DocumentService.php'));

        $this->assertStringContainsString('archiveForArtworkRevision($job)', $workflow);
        $this->assertStringContainsString("'Archived Supplier Purchase Order'", $service);
        $this->assertStringContainsString("whereNull('archived_at')", $service);
        $this->assertStringContainsString('supplier_purchase_order_sent', $service);
        $this->assertStringContainsString('validateSend(', $service);
        $this->assertStringContainsString("'Archived Supplier Purchase Order'", $documents);
        $this->assertStringNotContainsString('finishSupplierPurchaseOrderTask(', $page);
        $this->assertStringNotContainsString('ART_CREATE_SUPPLIER_PO', $workflow);
    }
}
