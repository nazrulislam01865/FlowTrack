<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderInquiryListExportImplementationTest extends TestCase
{
    public function test_order_and_inquiry_lists_offer_report_controlled_full_detail_exports(): void
    {
        $export = file_get_contents(app_path('Services/ListExportService.php'));
        $jobService = $this->jobServiceSource();
        $inquiryService = $this->inquiryServiceSource();
        $controller = file_get_contents(app_path('Http/Controllers/ListExportController.php'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $orderView = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $inquiryView = $this->inquiryViewSource();
        $periodModal = file_get_contents(resource_path('views/components/ui/list-export-period-modal.blade.php'));

        $this->assertStringContainsString("can(\$user, 'reports', 'export')", $export);
        $this->assertStringContainsString("canModule('reports', 'export')", $orderView);
        $this->assertStringContainsString("canModule('reports', 'export')", $inquiryView);
        $this->assertStringContainsString('list-export-period-modal', $orderView);
        $this->assertStringContainsString('list-export-period-modal', $inquiryView);
        $this->assertStringContainsString('⇩ Export', $periodModal);

        $this->assertStringContainsString('ordersListQuery(', $jobService);
        $this->assertStringContainsString('listQuery(User $user, array $filters)', $inquiryService);
        $this->assertStringContainsString("route('orders.export')", $orderView);
        $this->assertStringContainsString("route('inquiries.export')", $inquiryView);
        $this->assertStringContainsString('exportOrders($request->user()', $controller);
        $this->assertStringContainsString('exportInquiries($request->user()', $controller);
        $this->assertStringContainsString("name('orders.export')", $routes);
        $this->assertStringContainsString("name('inquiries.export')", $routes);
        $this->assertStringContainsString('permission:reports.export', $routes);

        $this->assertStringContainsString("'today'", $periodModal);
        $this->assertStringContainsString("'last_7_days'", $periodModal);
        $this->assertStringContainsString("'last_30_days'", $periodModal);
        $this->assertStringContainsString("'this_month'", $periodModal);
        $this->assertStringContainsString("'selected_month'", $periodModal);
        $this->assertStringContainsString("'all_time'", $periodModal);
        $this->assertStringContainsString("name=\"export_period\"", $periodModal);
        $this->assertStringContainsString("name=\"export_month\"", $periodModal);
        $this->assertStringContainsString('private function exportDateRange', $controller);
        $this->assertStringContainsString("'last_7_days' =>", $controller);
        $this->assertStringContainsString("'last_30_days' =>", $controller);
        $this->assertStringContainsString("'selected_month' =>", $controller);
        $this->assertStringContainsString("'all_time' => ['', '']", $controller);

        $this->assertStringContainsString("'Orders'", $export);
        $this->assertStringContainsString("'Order Details'", $export);
        $this->assertStringContainsString("'Products'", $export);
        $this->assertStringContainsString("'Tasks'", $export);
        $this->assertStringContainsString("'Task Comments'", $export);
        $this->assertStringContainsString("'Documents'", $export);
        $this->assertStringContainsString("'Activities'", $export);
        $this->assertStringContainsString("'Invoices'", $export);
        $this->assertStringContainsString("'Payments'", $export);
        $this->assertStringContainsString("'Inquiries'", $export);
        $this->assertStringContainsString("'Inquiry Details'", $export);
        $this->assertStringContainsString("'Current Task Status'", $export);
        $this->assertStringContainsString("'Task Assignee'", $export);
        $this->assertStringContainsString("'Assignee Email'", $export);
        $this->assertStringContainsString("'Task Last Updated At'", $export);
        $this->assertStringContainsString('orderProductSummary', $export);
        $this->assertStringContainsString('inquiryProductSummary', $export);
        $this->assertStringContainsString("\$canViewFinance ? \$item->unit_price : ''", $export);
        $this->assertStringContainsString("can(\$user, 'finance', 'view')", $export);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $export);
        $this->assertStringContainsString("method_exists(\$sheet, 'setShowGridlines')", $export);
        $this->assertStringContainsString("\$sheet->setShowGridlines(false)", $export);
        $this->assertStringNotContainsString("getSheetView()->setShowGridlines", $export);
    }
}
