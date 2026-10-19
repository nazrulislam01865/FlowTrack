<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupplierInquiryTaskBridgeScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_inquiry_task_action_is_rejected(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', 'test-bridge-secret-at-least-32-characters');
        $this->postJson('/api/supplier-bridge/inquiry-task-actions', [
            'supplier_id' => 1, 'invitation_id' => 1, 'task_id' => 1, 'action' => 'complete',
        ])->assertUnauthorized();
    }

    public function test_legacy_recovery_requires_no_existing_supplier_document_and_keeps_completion(): void
    {
        $action = file_get_contents(app_path('Http/Controllers/SupplierBridge/ReceiveSupplierInquiryTaskAction.php'));
        $this->assertStringContainsString("if ($".'task->completed_at)', $action);
        $this->assertStringContainsString("'Supplier RFQ '.".'$invitation->id', $action);
        $this->assertStringContainsString("'completed' => (bool) " . '$task->completed_at', $action);
        $this->assertStringContainsString("'file' => $".'this->fileReceipt($document)', $action);
    }

    public function test_task_uploads_are_not_in_shared_supplier_reference_manifest(): void
    {
        $download = file_get_contents(app_path('Http/Controllers/SupplierBridge/DownloadRfqDocument.php'));
        $this->assertStringContainsString("whereNull('inquiry_task_id')", $download);
        $projection = file_get_contents(app_path('Services/SupplierBridge/DeliverRfq.php'));
        $this->assertStringContainsString("'inquiry.tasks'", $projection);
        $this->assertStringContainsString('$docs = $inquiry->documents->map(', $projection);
    }
}
