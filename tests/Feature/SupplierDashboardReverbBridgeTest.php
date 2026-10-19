<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierDashboardReverbBridgeTest extends TestCase
{
    public function test_supplier_dashboard_reverb_uses_only_private_supplier_channels(): void
    {
        $channel = file_get_contents(app_path('Services/ReverbChannelService.php'));
        $auth = file_get_contents(app_path('Http/Controllers/SupplierBridge/AuthorizeSupplierRealtime.php'));
        $order = file_get_contents(app_path('Services/SupplierBridge/DeliverOrder.php'));
        $rfq = file_get_contents(app_path('Services/SupplierBridge/DeliverRfq.php'));

        $this->assertStringContainsString('private-flowtrack.supplier.', $channel);
        $this->assertStringContainsString('flowtrack.supplier.dashboard.updated', $channel);
        $this->assertStringContainsString("'resource' => ['sometimes', 'in:catalog,dashboard']", $auth);
        $this->assertStringContainsString('authenticateSupplierDashboard(', $auth);
        $this->assertStringContainsString('triggerSupplierDashboard(', $order);
        $this->assertStringContainsString('triggerSupplierDashboard(', $rfq);
    }
}
