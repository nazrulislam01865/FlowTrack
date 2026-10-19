<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierQuotationBridgeSecurityTest extends TestCase
{
    public function test_unauthenticated_buyer_submission_is_rejected_before_database_access(): void
    {
        config()->set('supplier_bridge.enabled',true);
        config()->set('supplier_bridge.secret','test-buyer-secret-at-least-32-characters');
        $this->postJson('/api/supplier-bridge/rfq-quotations',[
            'supplier_id'=>34785,'invitation_id'=>7,'quotation'=>[],
        ])->assertUnauthorized();
    }

    public function test_unsigned_file_upload_is_rejected(): void
    {
        config()->set('supplier_bridge.enabled',true);
        config()->set('supplier_bridge.secret','test-buyer-secret-at-least-32-characters');
        $this->postJson('/api/supplier-bridge/rfq-quotation-files',[])->assertUnauthorized();
    }
}
