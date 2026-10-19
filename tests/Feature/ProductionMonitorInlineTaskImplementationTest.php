<?php

namespace Tests\Feature;

use App\Services\OrderWorkflowSetupService;
use PHPUnit\Framework\TestCase;

class ProductionMonitorInlineTaskImplementationTest extends TestCase
{
    public function test_production_monitor_keeps_task_pack_contract(): void
    {
        $production = collect(OrderWorkflowSetupService::fixedStages())->firstWhere('key', 'prod');
        self::assertNotNull($production);

        $task = collect($production['tasks'])->firstWhere('automation_key', 'PROD_ISSUE');
        self::assertNotNull($task);
        self::assertSame('Monitor / Resolve Production Issue', $task['title']);
        self::assertFalse((bool) $task['is_required']);
    }

    public function test_production_monitor_uses_inline_prototype_and_persists_supplier_delivery_date(): void
    {
        $root = dirname(__DIR__, 2);
        $row = file_get_contents($root.'/resources/views/components/jobs/order-detail/task-row.blade.php');
        $inline = file_get_contents($root.'/resources/views/components/jobs/order-detail/production-monitor-inline.blade.php');
        $workflow = file_get_contents($root.'/app/Services/OrderWorkflowActionService.php');
        $report = file_get_contents($root.'/app/Services/OrderSummaryReportService.php');
        $model = file_get_contents($root.'/app/Models/FlowJob.php');
        $css = file_get_contents($root.'/resources/css/modules/orders/detail/permanent-task-grid.css');

        self::assertStringContainsString("\$isProductionMonitorTask = \$automationKey === 'PROD_ISSUE'", $row);
        self::assertStringContainsString('ft-order-task-row--production-monitor', $row);
        self::assertStringContainsString('saveProductionMonitorTask', $row);
        self::assertStringContainsString('Supplier Delivery Date', $inline);
        self::assertStringContainsString('Production Issue Note', $inline);
        self::assertStringContainsString('ft-prototype-clickable-date', $inline);
        self::assertStringContainsString('showPicker()', $inline);
        self::assertStringNotContainsString('This date will be used in order summary', $inline);
        self::assertStringContainsString("\$key === 'PROD_ISSUE' && \$decision === 'confirm'", $workflow);
        self::assertStringContainsString("'supplier_delivery_date' => \$supplierDeliveryDate", $workflow);
        self::assertStringContainsString('$order->supplier_delivery_date ?: $order->estimated_delivery_date', $report);
        self::assertStringContainsString("'supplier_delivery_date'", $model);
        self::assertStringContainsString('.ft-production-monitor-inline', $css);
    }

    public function test_production_monitor_has_no_required_fields(): void
    {
        $root = dirname(__DIR__, 2);
        $inline = file_get_contents($root.'/resources/views/components/jobs/order-detail/production-monitor-inline.blade.php');
        $modal = file_get_contents($root.'/resources/views/components/jobs/order-detail/workflow-action-modal.blade.php');
        $workflow = file_get_contents($root.'/app/Services/OrderWorkflowActionService.php');
        $livewire = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderWorkflow.php');

        self::assertStringContainsString('Supplier Delivery Date <em>(Optional)</em>', $inline);
        self::assertStringContainsString('Supplier Delivery Date <em>(Optional)</em>', $modal);
        self::assertStringNotContainsString('Supplier Delivery Date <b class="ft-production-monitor-required"', $inline);
        self::assertStringNotContainsString('Supplier Delivery Date <b class="ft-production-monitor-required"', $modal);
        self::assertStringNotContainsString('Supplier delivery date is required.', $workflow);
        self::assertStringNotContainsString('Supplier delivery date is missing. Set the date before editing the production issue note.', $livewire);
    }

    public function test_completed_production_monitor_keeps_saved_date_and_note_visible(): void
    {
        $root = dirname(__DIR__, 2);
        $row = file_get_contents($root.'/resources/views/components/jobs/order-detail/task-row.blade.php');
        $summary = file_get_contents($root.'/resources/views/components/jobs/order-detail/production-monitor-summary.blade.php');
        $detailView = file_get_contents($root.'/app/Services/OrderDetailViewService.php');
        $jobModel = file_get_contents($root.'/app/Models/FlowJob.php');
        $loader = file_get_contents($root.'/app/Services/LegacyJobService.php');

        self::assertStringContainsString('showProductionMonitorSummary', $row);
        self::assertStringContainsString('production-monitor-summary', $row);
        self::assertStringContainsString('Supplier Delivery Date', $summary);
        self::assertStringContainsString('Production Issue Note', $summary);
        self::assertStringContainsString('productionMonitorDetails', $detailView);
        self::assertStringContainsString('production_issue_note', $detailView);
        self::assertStringContainsString('latestProductionMonitorActivity', $jobModel);
        self::assertStringContainsString('latestProductionMonitorActivity:activities.id', $loader);
    }

    public function test_completed_production_monitor_supports_inline_editing_without_recompleting_the_task(): void
    {
        $root = dirname(__DIR__, 2);
        $summary = file_get_contents($root.'/resources/views/components/jobs/order-detail/production-monitor-summary.blade.php');
        $workflow = file_get_contents($root.'/app/Livewire/Jobs/Concerns/ManagesOrderWorkflow.php');

        self::assertStringContainsString('updateCompletedProductionMonitorSupplierDate', $summary);
        self::assertStringContainsString('updateCompletedProductionMonitorIssueNote', $summary);
        self::assertStringContainsString('ft-production-monitor-summary-date-input', $summary);
        self::assertStringContainsString('ft-production-monitor-summary-note-input', $summary);
        self::assertStringContainsString('updateCompletedProductionMonitorSupplierDate', $workflow);
        self::assertStringContainsString('updateCompletedProductionMonitorIssueNote', $workflow);
        self::assertStringContainsString("'edited_after_completion' => true", $workflow);
        self::assertStringContainsString("'changed_field' => \$changedField", $workflow);
    }

}
