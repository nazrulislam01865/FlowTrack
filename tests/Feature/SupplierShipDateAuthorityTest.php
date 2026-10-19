<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\SupplierWorkflowProjection;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierShipDateAuthorityTest extends TestCase
{
    /** The signed API must reject supplier-provided scheduling dates before looking up an order. */
    public function test_signed_supplier_task_actions_cannot_override_any_ship_or_delivery_date(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', 'test-bridge-secret-at-least-32-characters');

        foreach (['supplier_delivery_date', 'estimated_delivery_date', 'shipment_date'] as $dateField) {
            $event = (string) Str::uuid();
            $time = (string) time();
            $body = json_encode([
                'event_id' => $event, 'request_uuid' => (string) Str::uuid(),
                'supplier_id' => 1, 'order_id' => 1, 'task_id' => 1,
                'user_id' => 1, 'user_name' => 'Supplier staff',
                'decision' => 'confirm', 'expected_status' => 'Ready',
                'payload' => [$dateField => '2026-11-20'],
            ], JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $time."\n".$event."\n".$body, config('supplier_bridge.secret'));

            $this->call('POST', '/api/supplier-bridge/workflow-task-actions', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SUPPLIER_TIMESTAMP' => $time,
                'HTTP_X_SUPPLIER_EVENT' => $event,
                'HTTP_X_SUPPLIER_SIGNATURE' => $signature,
            ], $body)->assertForbidden();
        }
    }

    public function test_buyer_controlled_supplier_ship_date_is_synced_separately_from_estimated_delivery(): void
    {
        $projection = file_get_contents(app_path('Services/SupplierBridge/SupplierOrderProjection.php'));
        $this->assertStringContainsString("'supplier_ship_date' => \$order->supplier_delivery_date?->toDateString()", $projection);
        $this->assertStringContainsString("'delivery_date' => \$order->supplier_delivery_date?->toDateString()", $projection);
        $this->assertStringContainsString('PROD_SET_ESTIMATED_DELIVERY', implode(',', SupplierWorkflowProjection::ACTION_KEYS));

        $taskProjection = file_get_contents(app_path('Services/SupplierBridge/SupplierWorkflowProjection.php'));
        $this->assertStringContainsString("\$key !== 'PROD_SET_ESTIMATED_DELIVERY'", $taskProjection);
    }
}
