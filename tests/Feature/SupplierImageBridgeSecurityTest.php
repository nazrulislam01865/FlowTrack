<?php
namespace Tests\Feature;

use Tests\TestCase;

final class SupplierImageBridgeSecurityTest extends TestCase
{
    public function test_image_endpoint_requires_signed_supplier_request(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', str_repeat('k', 48));
        $this->get('/api/supplier-bridge/product-image/10/100')->assertUnauthorized();
        $this->withHeaders([
            'X-Supplier-Asset-Time' => (string) (time() - 601),
            'X-Supplier-Asset-Signature' => str_repeat('0', 64),
        ])->get('/api/supplier-bridge/product-image/10/100')->assertUnauthorized();
    }

    public function test_event_driven_changes_keep_durable_retry_paths(): void
    {
        $catalog = file_get_contents(app_path('Services/SupplierBridge/MarkCatalogDirty.php'));
        $order = file_get_contents(app_path('Services/SupplierBridge/QueueChangedOrder.php'));
        $this->assertStringContainsString('supplier_catalog_outbox', $catalog);
        $this->assertStringContainsString('DeliverChangedSupplierCatalog::dispatch', $catalog);
        $this->assertStringContainsString('supplier_order_deliveries', $order);
        $this->assertStringContainsString('DeliverChangedSupplierOrder::dispatch', $order);
    }
}
