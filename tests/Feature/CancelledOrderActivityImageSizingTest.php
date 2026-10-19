<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CancelledOrderActivityImageSizingTest extends TestCase
{
    public function test_cancelled_order_activity_evidence_is_rendered_as_a_compact_clickable_preview(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/components/jobs/order-detail/activity.blade.php');
        $css = file_get_contents($root.'/resources/css/modules/orders/detail/detail-01.css');
        $richTextJs = file_get_contents($root.'/resources/js/components/rich-text.js');

        self::assertStringContainsString("\$isCancellation = \$activity->event === 'job.cancelled';", $view);
        self::assertStringContainsString('ft-rich-text-content ft-order-cancellation-activity-copy', $view);
        self::assertStringContainsString('.ft-order-cancellation-activity-copy img', $css);
        self::assertStringContainsString('width: min(100%, 320px) !important;', $css);
        self::assertStringContainsString('height: 190px !important;', $css);
        self::assertStringContainsString('object-fit: contain;', $css);
        self::assertStringContainsString("closest?.('.ft-rich-text-content img')", $richTextJs);
    }
}
