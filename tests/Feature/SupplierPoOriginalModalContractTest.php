<?php
namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SupplierPoOriginalModalContractTest extends TestCase
{
    #[Test]
    public function the_artwork_create_action_opens_the_original_generator_without_writing(): void
    {
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $page = file_get_contents(app_path('Livewire/Orders/PurchaseOrders.php'));
        $view = file_get_contents(resource_path('views/livewire/orders/purchase-orders.blade.php'));
        $panel = file_get_contents(resource_path('views/components/jobs/order-detail/supplier-purchase-order-panel.blade.php'));
        $this->assertStringContainsString("'create' => 1", $workflow);
        $this->assertStringContainsString('ft-po-create-popup', $view);
        $this->assertStringContainsString('Single Purchase Order', $panel);
        $this->assertStringContainsString('Split Purchase Orders', $panel);
        $this->assertStringContainsString('supplier_po_template', $panel);
        $this->assertStringContainsString('artworkTaskAction: true', $page);
        $this->assertStringContainsString("'supplier_po_items'] ?? []", $page);
        $this->assertStringContainsString("'supplier_po_delivery_date'] ?? null", $page);
        $this->assertStringContainsString('abort_unless($this->create', $page);
    }

    #[Test]
    public function supplier_action_projection_supports_only_explicit_po_task_actions(): void
    {
        $projection = file_get_contents(app_path('Services/SupplierBridge/SupplierWorkflowProjection.php'));
        $endpoint = file_get_contents(app_path('Http/Controllers/SupplierBridge/ReceiveSupplierPurchaseOrder.php'));
        $this->assertStringContainsString("'ART_SUPPLIER_PO_OPTIONAL' => ['skip']", $projection);
        $this->assertStringContainsString("'split_item_ids'", $endpoint);
        $this->assertStringContainsString("'pos' => $projection", $endpoint);
        $this->assertStringContainsString('SupplierTaskAssignment::query()', $endpoint);
    }
}
