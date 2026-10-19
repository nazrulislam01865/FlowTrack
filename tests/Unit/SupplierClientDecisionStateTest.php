<?php

namespace Tests\Unit;

use App\Services\SupplierBridge\SupplierWorkflowProjection;
use PHPUnit\Framework\TestCase;

final class SupplierClientDecisionStateTest extends TestCase
{
    public function test_imported_unstarted_client_decision_uses_erp_upload_action(): void
    {
        // The separate taskMode check must still confirm that all earlier
        // required steps are complete and that this is the active stage.
        self::assertSame(['erp_uploaded'], SupplierWorkflowProjection::decisionsForStatus(
            'ART_CLIENT_ERP_DECISION', 'Not Started',
        ));
    }

    public function test_sample_waiting_state_cannot_reopen_erp_upload(): void
    {
        self::assertSame([], SupplierWorkflowProjection::decisionsForStatus(
            'ART_CLIENT_ERP_DECISION', 'Waiting for Sample Approval',
        ));
    }

    public function test_waiting_for_client_offers_only_client_decisions(): void
    {
        self::assertSame(['confirm', 'sample', 'revise'], SupplierWorkflowProjection::decisionsForStatus(
            'ART_CLIENT_ERP_DECISION', 'Waiting for Client',
        ));
    }
}
