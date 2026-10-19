<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryProductRfqOverviewPrototypeTest extends TestCase
{
    public function test_inquiry_overview_uses_reusable_product_rfq_prototype_components(): void
    {
        $detail = file_get_contents(resource_path('views/livewire/inquiries/sections/detail.blade.php'));
        $overview = file_get_contents(resource_path('views/components/inquiries/product-rfq-overview.blade.php'));
        $row = file_get_contents(resource_path('views/components/inquiries/product-rfq-overview-row.blade.php'));
        $stat = file_get_contents(resource_path('views/components/inquiries/product-rfq-stat.blade.php'));
        $css = file_get_contents(resource_path('css/modules/application/23-inquiry-product-rfq-overview.css'));
        $core = file_get_contents(resource_path('css/application/core.css'));

        $this->assertStringContainsString('<x-inquiries.product-rfq-overview', $detail);
        $this->assertStringContainsString('<x-inquiries.product-rfq-overview-row', $detail);
        $this->assertStringContainsString('Products, suppliers &amp; RFQ progress', $overview);
        $this->assertStringContainsString('supplier assignments', $overview);
        $this->assertStringContainsString('invitations sent', $overview);
        $this->assertStringContainsString('quotations received', $overview);
        $this->assertStringContainsString('Assigned suppliers', $overview);
        $this->assertStringContainsString('RFQ progress', $overview);
        $this->assertStringContainsString('View details', $row);
        $this->assertStringContainsString('<article', $stat);
        $this->assertStringContainsString('.ft-inquiry-prq-stats', $css);
        $this->assertStringContainsString('.ft-inquiry-prq-table', $css);
        $this->assertStringContainsString("@import '../modules/application/23-inquiry-product-rfq-overview.css';", $core);
    }

    public function test_inquiry_product_rfq_overview_data_is_prepared_outside_blade(): void
    {
        $builder = file_get_contents(app_path('Livewire/Inquiries/Concerns/BuildsInquiryPageData.php'));
        $catalog = file_get_contents(app_path('Services/ProductCatalogService.php'));
        $rfq = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $presenter = file_get_contents(app_path('Support/InquiryProductRfqOverviewPresenter.php'));

        $this->assertStringContainsString('allSuppliersForProducts', $builder);
        $this->assertStringContainsString('overviewInvitations', $builder);
        $this->assertStringContainsString('InquiryProductRfqOverviewPresenter::build', $builder);
        $this->assertStringContainsString('public function allSuppliersForProducts(Collection $products): Collection', $catalog);
        $this->assertStringContainsString("Schema::hasTable('product_supplier_links')", $catalog);
        $this->assertStringContainsString('public function overviewInvitations(Inquiry $inquiry): Collection', $rfq);
        $this->assertStringContainsString("'quote.items:id,quote_id,inquiry_item_id'", $rfq);
        $this->assertStringContainsString("'supplier_assignments'", $presenter);
        $this->assertStringContainsString("'invitations_sent'", $presenter);
        $this->assertStringContainsString("'quotations_received'", $presenter);
    }
}
