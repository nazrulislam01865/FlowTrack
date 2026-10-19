<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryRfqSupplierListParityTest extends TestCase
{
    public function test_inquiry_details_rfq_uses_supplier_directory_rows_and_keeps_unavailable_suppliers_visible(): void
    {
        $rfq = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $pageData = file_get_contents(app_path('Livewire/Inquiries/Concerns/BuildsInquiryPageData.php'));
        $view = file_get_contents(resource_path('views/livewire/inquiries/sections/rfq.blade.php'));

        $this->assertStringContainsString('return $this->supplierChoicesForWorkspace((int) $inquiry->workspace_id, $search, $limit, $excludeSupplierIds);', $rfq);
        $this->assertStringContainsString('candidateSuppliers($inquiry, $this->rfqSupplierSearch, 100)', $pageData);
        $this->assertStringContainsString('from Supplier list', $view);
        $this->assertStringContainsString("candidate['invitable']", $view);
        $this->assertStringContainsString('ft-rfq-candidate-unavailable', $view);
        $this->assertStringContainsString('No email configured', $view);
    }
}
