<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierWorkflowDeliveryReliabilityTest extends TestCase
{
    public function test_reconciliation_runs_before_delivery_and_includes_assignment_only_changes(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("from('supplier_task_assignments as a')", $console);
        $this->assertStringContainsString("->whereColumn('a.flow_job_id', 'd.flow_job_id')", $console);
        $this->assertStringContainsString(
            "Schedule::command('flowtrack:refresh-supplier-orders')->everyFifteenMinutes()", $console
        );
        $this->assertStringContainsString(
            "Schedule::command('flowtrack:sync-supplier-orders')->everyMinute()", $console
        );
        $this->assertLessThan(
            strpos($console, "Schedule::command('flowtrack:sync-supplier-orders')->everyMinute()"),
            strpos($console, "Schedule::command('flowtrack:refresh-supplier-orders')->everyFifteenMinutes()")
        );
    }

    public function test_projection_upgrade_reconciles_delivered_orders_without_another_task_edit(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));
        $sender = file_get_contents(app_path('Services/SupplierBridge/DeliverOrder.php'));
        $projection = \App\Services\SupplierBridge\SupplierOrderProjection::class;

        $this->assertGreaterThan(0, $projection::FORMAT_VERSION);
        $this->assertStringContainsString("whereNull('d.payload->projection_version')", $console);
        $this->assertStringContainsString('SupplierOrderProjection::FORMAT_VERSION', $console);
        $this->assertStringContainsString("$"."metadata['projection_version'] = SupplierOrderProjection::FORMAT_VERSION", $sender);
        $this->assertStringContainsString("->whereNull('next_attempt_at')->update([", $sender);
    }

    public function test_changes_during_signed_delivery_are_not_overwritten(): void
    {
        $observer = file_get_contents(app_path('Observers/SupplierBridgeSyncObserver.php'));
        $assigner = file_get_contents(app_path('Services/SupplierBridge/SupplierDefaultTaskAssigner.php'));
        $sender = file_get_contents(app_path('Services/SupplierBridge/DeliverOrder.php'));

        $queue = file_get_contents(app_path('Services/SupplierBridge/QueueChangedOrder.php'));
        $this->assertStringContainsString("->where('status', 'sending')->update([", $queue);
        $this->assertStringContainsString("->whereNotNull('next_attempt_at')", $sender);
        $this->assertStringContainsString("->onQueue('supplier-sync')", $queue);
        $this->assertStringContainsString("->syncForOrder(\$order)", $sender);
        $this->assertStringContainsString("\$response->json('applied') === false", $sender);
    }
}
