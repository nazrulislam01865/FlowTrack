<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderListPurchaseOrderRowColorTest extends TestCase
{
    public function test_new_order_row_color_is_driven_only_by_purchase_order_document_evidence(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));
        $view = file_get_contents(resource_path('views/components/orders/list/table.blade.php'));

        $this->assertStringContainsString('private function wherePurchaseOrderDocument', $service);
        $this->assertStringContainsString("->whereHas('documents')", $service);
        $this->assertStringContainsString('$purchaseOrderEvidenceColor = $poDocument ? \'#159A68\' : null;', $service);
        $this->assertStringContainsString('$activeTaskColor = $purchaseOrderEvidenceColor;', $service);
        $this->assertStringContainsString('$stageQuickColor = $purchaseOrderEvidenceColor;', $service);
        $this->assertStringContainsString("return \$purchaseOrderTask?->documents?->isNotEmpty() ? 'po_uploaded' : 'po_pending';", $service);
        $this->assertStringContainsString("'po_status' => \$poDocument ? 'PO Uploaded' : 'PO Pending'", $service);
        $this->assertStringNotContainsString('OrderDetailPresenter::isCompletedTask($poTask)', $service);

        // The existing table color mechanism remains the single rendering path:
        // null means plain white; the PO evidence color produces the green tint.
        $this->assertStringContainsString('MasterColor::taskRowStyle($rowColor)', $view);
        $this->assertStringContainsString("data_get(\$row, 'stage_filter_color')", $view);
        $this->assertStringContainsString('$activeTaskColor', $view);
    }
}
