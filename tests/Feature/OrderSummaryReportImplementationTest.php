<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class OrderSummaryReportImplementationTest extends TestCase
{
    public function test_order_summary_report_keeps_the_approved_template_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/livewire/reports/order-summary.blade.php');
        $routes = file_get_contents($root.'/routes/web.php');
        $sidebar = file_get_contents($root.'/resources/views/layouts/partials/sidebar.blade.php');
        $service = file_get_contents($root.'/app/Services/OrderSummaryReportService.php');

        foreach ([
            'Order Summary Report',
            'Supplier, material, sample and delivery tracking in one operational report.',
            'Download Excel',
            'Supplier',
            'Warehouse',
            'Order No.',
            'Received Date',
            'Urgent or Not',
            'Quantity',
            'Material',
            'Artwork Approval Date',
            'Special Orders',
            'Sample/Swatch Sent Date',
            'Sample/Swatch Confirmed Date',
            'Revise / Sample Confirm Date',
            '供应商到货日期',
            '供应商回复交期',
            'Awaiting supplier reply',
            'Horizontal scroll available',
        ] as $needle) {
            self::assertStringContainsString($needle, $view);
        }

        self::assertStringContainsString("name('order-summary.report')", $routes);
        self::assertStringContainsString("name('order-summary.export')", $routes);
        self::assertStringContainsString('label="Order Summary"', $sidebar);
        self::assertStringContainsString('new Xlsx($book)', $service);
        self::assertStringContainsString("setTitle('Order Summary')", $service);
        self::assertStringContainsString("'artwork_approval' => \$this->date(\$artworkApprovalAt)", $service);
        self::assertStringContainsString("'sample_sent' => \$this->date(\$artworkSentActivity?->created_at)", $service);
        self::assertStringContainsString("'sample_confirmed' => \$this->date(\$clientApprovalActivity?->created_at)", $service);
        self::assertStringContainsString("'revise_confirm' => \$this->date(\$revisionActivity?->created_at)", $service);
        self::assertStringContainsString("'job.artwork_client_erp_uploaded'", $service);
    }
}
