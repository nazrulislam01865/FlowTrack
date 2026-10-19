<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryRfqFailurePresentationTest extends TestCase
{
    public function test_delivery_failure_is_shown_once_at_product_level_and_failed_status_has_no_detail_message(): void
    {
        $index = file_get_contents(resource_path('views/livewire/inquiries/index.blade.php'));
        $workspace = file_get_contents(resource_path('views/components/inquiries/rfq-product-workspace.blade.php'));
        $group = file_get_contents(resource_path('views/components/inquiries/rfq-product-group.blade.php'));
        $row = file_get_contents(resource_path('views/components/inquiries/rfq-product-supplier-row.blade.php'));
        $presenter = file_get_contents(app_path('Support/InquiryRfqProductWorkspacePresenter.php'));

        $this->assertStringContainsString("->except(['rfqDelivery'])", $index);
        $this->assertStringNotContainsString("@error('rfqDelivery')", $workspace);
        $this->assertStringContainsString('failed for this product. Review the error and retry.', $group);
        $this->assertStringContainsString("(\$row['status_key'] ?? '') !== 'failed'", $row);
        $this->assertStringContainsString("\$rawStatus === 'failed' => ['failed', 'Failed', 'danger', null]", $presenter);
        $this->assertStringNotContainsString('Delivery failed — review the supplier email and retry.', $presenter);
    }
}
