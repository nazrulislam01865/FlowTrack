<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupplierPurchaseOrderBridgeSecurityTest extends TestCase
{
    public function test_unsigned_supplier_po_mutation_is_rejected_before_any_database_access(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', str_repeat('s', 40));
        $this->postJson('/api/supplier-bridge/purchase-orders', [
            'supplier_id' => 5, 'order_id' => 100,
            'action' => 'create', 'items' => [],
        ])->assertUnauthorized();
    }

    public function test_unsigned_supplier_po_pdf_download_is_not_available(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', str_repeat('s', 40));
        $this->get('/api/supplier-bridge/purchase-order-file/100/2/7')->assertNotFound();
    }
}
