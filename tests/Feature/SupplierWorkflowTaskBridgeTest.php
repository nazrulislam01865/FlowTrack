<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\SupplierWorkflowProjection;
use Tests\TestCase;

class SupplierWorkflowTaskBridgeTest extends TestCase
{
    public function test_supplier_actions_are_limited_to_first_five_stages(): void
    {
        $this->assertSame('NEW_UPLOAD_PO', SupplierWorkflowProjection::actionKey('NEW_UPLOAD_PO', 1));
        $this->assertSame('ART_PREPARE_UPLOAD', SupplierWorkflowProjection::actionKey('ART_PREPARE_UPLOAD', 2));
        $this->assertSame('GENERIC_TASK', SupplierWorkflowProjection::actionKey(null, 5));
        $this->assertNull(SupplierWorkflowProjection::actionKey('BILL_PREPARE', 6));
        $this->assertNull(SupplierWorkflowProjection::actionKey('PAY_PROCESS', 7));
        $this->assertNull(SupplierWorkflowProjection::actionKey('UNRECOGNIZED_PRIVILEGED_ACTION', 3));
        $this->assertContains('upload', SupplierWorkflowProjection::decisions('NEW_UPLOAD_PO'));
        $this->assertNotContains('upload', SupplierWorkflowProjection::decisions('PROD_START'));
    }

    public function test_client_erp_upload_requires_separate_client_decision(): void
    {
        $this->assertSame(['erp_uploaded'],
            SupplierWorkflowProjection::decisionsForStatus('ART_CLIENT_ERP_DECISION', 'Ready'));
        $this->assertSame(['confirm', 'sample', 'revise'],
            SupplierWorkflowProjection::decisionsForStatus('ART_CLIENT_ERP_DECISION', 'Waiting for Client'));
        $this->assertNotContains('erp_uploaded',
            SupplierWorkflowProjection::decisionsForStatus('ART_CLIENT_ERP_DECISION', 'Waiting for Client'));
        $this->assertSame([],
            SupplierWorkflowProjection::decisionsForStatus('ART_CLIENT_ERP_DECISION', 'Waiting for Sample Approval'));
        $this->assertSame(['pass', 'issue'],
            SupplierWorkflowProjection::decisionsForStatus('QC_CHECK', 'Ready'));
    }

    public function test_workflow_bridge_rejects_unsigned_task_action_before_accessing_database(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('supplier_bridge.secret', 'test-bridge-secret-at-least-32-characters');
        $this->postJson('/api/supplier-bridge/workflow-task-actions', [
            'supplier_id' => 1, 'order_id' => 1, 'task_id' => 1,
        ])->assertUnauthorized();
    }
}
