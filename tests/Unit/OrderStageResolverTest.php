<?php

namespace Tests\Unit;

use App\Support\OrderStageResolver;
use PHPUnit\Framework\TestCase;

class OrderStageResolverTest extends TestCase
{
    public function test_legacy_order_intake_is_presented_as_new_order(): void
    {
        $stage = OrderStageResolver::resolve('Order Intake', 'Intake', 1, 'New');

        self::assertSame('New Order', $stage['name']);
        self::assertSame('New Order', $stage['short_name']);
        self::assertSame(1, $stage['sequence']);
    }

    public function test_automation_key_is_authoritative_for_current_seven_stage_runtime(): void
    {
        self::assertSame(1, OrderStageResolver::resolve('Legacy label', null, 4, null, 'NEW_UPLOAD_PO')['sequence']);
        self::assertSame(2, OrderStageResolver::resolve('Legacy label', null, 4, null, 'ART_INTERNAL_REVIEW')['sequence']);
        self::assertSame(3, OrderStageResolver::resolve('Legacy label', null, 4, null, 'PROD_START')['sequence']);
        self::assertSame(4, OrderStageResolver::resolve('Legacy label', null, 4, null, 'QC_CHECK')['sequence']);
        self::assertSame(5, OrderStageResolver::resolve('Legacy label', null, 4, null, 'SHIP_PACKAGE')['sequence']);
        self::assertSame(6, OrderStageResolver::resolve('Legacy label', null, 4, null, 'BILL_PREPARE')['sequence']);
        self::assertSame(7, OrderStageResolver::resolve('Legacy label', null, 4, null, 'PAY_PROCESS')['sequence']);
    }

    public function test_old_combined_stages_are_split_using_operational_status_evidence(): void
    {
        self::assertSame(4, OrderStageResolver::resolve('QC & Dispatch', null, 4, 'In Progress')['sequence']);
        self::assertSame(5, OrderStageResolver::resolve('QC & Dispatch', null, 4, 'Ready to ship')['sequence']);
        self::assertSame(6, OrderStageResolver::resolve('Invoice & Payment', null, 5, 'Invoice prepared')['sequence']);
        self::assertSame(7, OrderStageResolver::resolve('Invoice & Payment', null, 5, 'Partially Paid')['sequence']);
    }
}
