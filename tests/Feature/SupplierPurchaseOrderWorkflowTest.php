<?php

namespace Tests\Feature;

use App\Services\OrderWorkflowSetupService;
use Tests\TestCase;

class SupplierPurchaseOrderWorkflowTest extends TestCase
{
    public function test_supplier_po_is_the_final_artwork_task_after_client_and_sample_approval(): void
    {
        // Both published Order workflow variants are seeded from fixedStages.
        $stages = OrderWorkflowSetupService::fixedStages();
        $artwork = collect($stages)->firstWhere('key', 'art');
        $this->assertNotNull($artwork);
        $keys = array_column($artwork['tasks'], 'automation_key');
        $review = array_search('ART_INTERNAL_REVIEW', $keys, true);
        $this->assertNotFalse($review);
        $this->assertSame('ART_CREATE_SUPPLIER_PO', end($keys));
        $this->assertGreaterThan(array_search('ART_CLIENT_ERP_DECISION', $keys, true), array_search('ART_CREATE_SUPPLIER_PO', $keys, true));
        $this->assertGreaterThan(array_search('ART_SAMPLE_APPROVAL', $keys, true), array_search('ART_CREATE_SUPPLIER_PO', $keys, true));
        $this->assertGreaterThan($review, array_search('ART_CREATE_SUPPLIER_PO', $keys, true));
        $this->assertContains('ART_SEND_ORDER_TEAM', $keys);

        $po = collect($artwork['tasks'])->firstWhere('automation_key', 'ART_CREATE_SUPPLIER_PO');
        $this->assertTrue($po['is_required']);
        $this->assertFalse($po['document_required_before_completion']);
        $this->assertSame('Supplier Purchase Order', $po['document_type']);

        $newOrder = collect($stages)->firstWhere('key', 'new');
        $this->assertContains('NEW_UPLOAD_PO', array_column($newOrder['tasks'], 'automation_key'));
    }

    public function test_existing_order_task_packs_receive_idempotent_po_reorder_migration(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_11_03_000004_move_supplier_po_to_artwork_end.php'));
        $this->assertStringContainsString("where('flow.applies_to', 'orders')", $migration);
        $this->assertStringContainsString("where('phase.sequence', 2)", $migration);
        $this->assertStringContainsString("'ART_CREATE_SUPPLIER_PO'", $migration);
        $this->assertStringContainsString("'task_pack_tasks'", $migration);
    }

    public function test_both_order_interfaces_use_the_same_po_workflow_modal_and_actions(): void
    {
        $list = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $details = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $detailsWorkflowSection = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $detailsTrait = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $shared = file_get_contents(app_path('Livewire/Concerns/ManagesSupplierPurchaseOrders.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $panel = file_get_contents(resource_path('views/components/jobs/order-detail/supplier-purchase-order-panel.blade.php'));
        $task = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));

        foreach ([$list, $details, $detailsWorkflowSection] as $surface) {
            $this->assertStringContainsString('ManagesSupplierPurchaseOrders', $surface);
        }
        foreach ([$list, $detailsTrait] as $surface) {
            $this->assertStringContainsString('prepareSupplierPoModal($task)', $surface);
        }
        $this->assertStringContainsString("@elseif(\$variant === 'supplier_po')", $modal);
        $this->assertStringContainsString('<x-jobs.order-detail.supplier-purchase-order-panel', $modal);
        $this->assertStringContainsString('supplier_po_mode', $panel);
        $this->assertStringContainsString('supplier_po_item_ids', $panel);
        $this->assertStringContainsString('sendSupplierPurchaseOrder(', $panel);
        $this->assertStringContainsString('sendAllSupplierPurchaseOrders', $panel);
        $this->assertStringContainsString('openGeneratedSupplierPurchaseOrders(', $task);
        $this->assertStringContainsString('createSupplierPurchaseOrders()', $shared);
        $this->assertStringContainsString('sendSupplierPurchaseOrder(int $poId)', $shared);
    }

    public function test_po_action_methods_exist_on_both_active_livewire_workflow_hosts(): void
    {
        // The Order Details page renders the isolated OrderWorkflowSection, not
        // just the legacy Jobs\Index component. Omitting the shared trait from
        // this child causes a BadMethodCallException when opening Create PO.
        foreach ([
            \App\Livewire\Jobs\OrderWorkflowSection::class,
            \App\Livewire\Jobs\Index::class,
            \App\Livewire\Orders\Index::class,
        ] as $host) {
            foreach ([
                'prepareSupplierPoModal',
                'createSupplierPurchaseOrders',
                'openGeneratedSupplierPurchaseOrders',
                'sendSupplierPurchaseOrder',
                'sendAllSupplierPurchaseOrders',
            ] as $action) {
                $this->assertTrue(method_exists($host, $action), $host.' missing '.$action);
            }
        }
    }

    public function test_po_modal_reuses_client_decision_cards_and_artwork_style_recipient_fields(): void
    {
        $panel = file_get_contents(resource_path('views/components/jobs/order-detail/supplier-purchase-order-panel.blade.php'));
        $workflow = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $trait = file_get_contents(app_path('Livewire/Concerns/ManagesSupplierPurchaseOrders.php'));
        $service = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderService.php'));

        $this->assertStringContainsString('ft-prototype-choice-grid ft-supplier-po-choices', $panel);
        $this->assertStringContainsString('chooseSupplierPurchaseOrderMode(', $panel);
        $this->assertStringContainsString('selectSupplierPurchaseOrder(', $panel);
        $this->assertStringContainsString('addSupplierPoRecipient(', $panel);
        $this->assertStringContainsString('removeSupplierPoRecipient(', $panel);
        $this->assertStringContainsString('supplier_po_recipients', $trait);
        $this->assertStringContainsString("'products' => \$rows ? [] :", $trait);
        $this->assertStringContainsString("'Send Purchase Order'", $workflow);
        $this->assertStringContainsString("'supplier_po'", $workflow);
        $this->assertStringContainsString('EmailMessage::storageAttachment(', $service);
    }

    public function test_po_generation_is_separate_from_customer_po_and_does_not_expose_customer_price(): void
    {
        $service = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderService.php'));
        $migration = file_get_contents(database_path('migrations/2026_11_03_000001_create_supplier_purchase_orders.php'));
        $workflow = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $documents = file_get_contents(app_path('Services/DocumentService.php'));

        $this->assertStringContainsString("'supplier_unit_cost' => \$item->supplier_unit_price", $service);
        $this->assertStringNotContainsString('$item->unit_price', $service);
        $this->assertStringContainsString("'Supplier Purchase Order'", $service);
        $this->assertStringContainsString('EmailMessage::storageAttachment(', $service);
        $this->assertStringContainsString('validateSend(', $service);
        $this->assertStringContainsString('groupBy(fn (FlowJobItem', $service);
        $this->assertStringContainsString("whereIn('email_status', ['not_sent', 'failed'])", file_get_contents(app_path('Livewire/Concerns/ManagesSupplierPurchaseOrders.php')));
        $this->assertStringContainsString("Schema::create('supplier_purchase_orders'", $migration);
        $this->assertStringContainsString('mirrorPackToLegacy(', $migration);
        $this->assertStringContainsString("'ART_CREATE_SUPPLIER_PO'", $workflow);
        $this->assertStringContainsString("'Archived Supplier Purchase Order'", $documents);
    }

    public function test_supplier_po_email_optional_completion_and_revision_archival_are_separate_operations(): void
    {
        $actions = file_get_contents(app_path('Livewire/Concerns/ManagesSupplierPurchaseOrders.php'));
        $workflow = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $service = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderService.php'));
        $panel = file_get_contents(resource_path('views/components/jobs/order-detail/supplier-purchase-order-panel.blade.php'));
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));

        $this->assertStringContainsString('completeSupplierPurchaseOrdersWithoutEmail()', $actions);
        $this->assertStringContainsString('finishSupplierPoIfAllSent(', $actions);
        $this->assertStringContainsString('completeSupplierPurchaseOrdersWithoutEmail', $panel);
        $this->assertStringContainsString("=== 'failed' ? 'Resend PO'", $panel);
        $this->assertStringContainsString('Complete without email', $panel);
        $this->assertStringContainsString('archiveForArtworkRevision($poTask)', $workflow);
        $this->assertStringContainsString("whereNull('archived_at')", $service);
        $this->assertStringContainsString("whereNull('archived_at')", $workflow);
        $this->assertStringContainsString('Archived Purchase Orders', $taskRow);
        $this->assertFileExists(database_path('migrations/2026_11_03_000003_archive_supplier_purchase_orders_on_artwork_revision.php'));
        $this->assertStringContainsString('Supplier Purchase Order', $service);
    }
}
