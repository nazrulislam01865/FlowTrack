<?php

namespace Tests\Feature;

use App\Services\OrderWorkflowActionService;
use App\Services\SupplierBridge\SupplierWorkflowProjection;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/** Zero-database contract tests for the ERP's supplier QC decision surface. */
class SupplierQcWorkflowContractTest extends TestCase
{
    public function test_supplier_qc_decisions_match_the_existing_flowtracker_stages(): void
    {
        $this->assertSame('QC_CHECK', SupplierWorkflowProjection::actionKey('QC_CHECK', 4));
        $this->assertSame('QC_ISSUE', SupplierWorkflowProjection::actionKey('QC_ISSUE', 4));
        $this->assertSame('QC_APPROVE_SHIPMENT', SupplierWorkflowProjection::actionKey('QC_APPROVE_SHIPMENT', 4));
        $this->assertSame(['pass', 'issue'], SupplierWorkflowProjection::decisions('QC_CHECK'));
        $this->assertContains('resolve', SupplierWorkflowProjection::decisions('QC_ISSUE'));
        $this->assertSame(['confirm'], SupplierWorkflowProjection::decisions('QC_APPROVE_SHIPMENT'));
        $this->assertNotContains('upload', SupplierWorkflowProjection::decisions('QC_CHECK'));
    }

    public function test_qc_activity_metadata_keeps_comments_and_quantities(): void
    {
        $method = new ReflectionMethod(OrderWorkflowActionService::class, 'qcMeta');
        $meta = $method->invoke(new OrderWorkflowActionService(), [
            'qty_received' => 50, 'qty_inspected' => 5, 'qty_accepted' => 3,
            'qty_rejected' => 2, 'qc_comments' => 'Supplier checked stitching.',
            'issue_category' => 'Damaged items', 'unexpected' => 'not exported',
        ]);
        $this->assertSame([
            'qty_received' => 50, 'qty_inspected' => 5, 'qty_accepted' => 3,
            'qty_rejected' => 2, 'qc_comments' => 'Supplier checked stitching.',
            'issue_category' => 'Damaged items',
        ], $meta);
    }

    public function test_canonical_qc_quantity_rules_reject_inconsistent_inspections(): void
    {
        $method = new ReflectionMethod(OrderWorkflowActionService::class, 'validateQcPayload');
        $this->expectException(ValidationException::class);
        $method->invoke(new OrderWorkflowActionService(), [
            'qty_received' => 10, 'qty_inspected' => 11,
            'qty_accepted' => 8, 'qty_rejected' => 3,
        ]);
    }
    public function test_qc_document_is_task_evidence_not_a_qc_status_decision(): void
    {
        $receiver = file_get_contents(app_path('Http/Controllers/SupplierBridge/ReceiveSupplierTaskAction.php'));
        $projection = file_get_contents(app_path('Services/SupplierBridge/SupplierWorkflowProjection.php'));
        $this->assertStringContainsString("$"."qcAttachment", $receiver);
        $this->assertStringContainsString("'document_action_key'", $projection);
        $this->assertStringContainsString("'can_attach'", $projection);
        $this->assertStringContainsString("'document_files'", $projection);
        $this->assertStringContainsString("? $"."task // Evidence only", $receiver);
    }

    public function test_qc_document_download_rejects_unsigned_requests(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', 'test-bridge-secret-at-least-32-characters');
        $this->get('/api/supplier-bridge/order-qc-documents/123/456')->assertNotFound();
    }

}
