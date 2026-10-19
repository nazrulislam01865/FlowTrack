<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierProductionTargetAndCostingFilesContractTest extends TestCase
{
    public function test_production_target_is_explicit_and_stays_flowtracker_controlled(): void
    {
        $projection = file_get_contents(app_path('Services/SupplierBridge/SupplierOrderProjection.php'));
        $this->assertStringContainsString("'estimated_delivery_date' => \$order->estimated_delivery_date?->toDateString()", $projection);
        $action = file_get_contents(app_path('Services/SupplierBridge/SupplierWorkflowProjection.php'));
        $this->assertStringContainsString("\$key !== 'PROD_SET_ESTIMATED_DELIVERY'", $action);
    }

    public function test_costing_files_are_scoped_to_a_single_supplier_invitation(): void
    {
        $projection = file_get_contents(app_path('Services/SupplierBridge/DeliverRfq.php'));
        $this->assertStringContainsString("where('note', 'like', 'Supplier RFQ '.\$invitation->id.' |%')", $projection);
        $this->assertStringContainsString("'uploaded_files' => \$submittedFiles", $projection);
        $download = file_get_contents(app_path('Http/Controllers/SupplierBridge/DownloadRfqDocument.php'));
        $this->assertStringContainsString("where('note', 'like', 'Supplier RFQ '.\$rfq->id.' |%')", $download);
        $this->assertStringContainsString("where('inquiry_task_id', \$firstTaskId)", $download);
    }
}
