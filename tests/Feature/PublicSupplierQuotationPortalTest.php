<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicSupplierQuotationPortalTest extends TestCase
{
    public function test_public_supplier_portal_is_composed_from_reusable_step_components(): void
    {
        $view = file_get_contents(resource_path('views/rfq/public-show.blade.php'));

        $this->assertStringContainsString('<x-rfq.public.header', $view);
        $this->assertStringContainsString('<x-rfq.public.stepper', $view);
        $this->assertStringContainsString('<x-rfq.public.details', $view);
        $this->assertStringContainsString('<x-rfq.public.pricing', $view);
        $this->assertStringContainsString('<x-rfq.public.documents', $view);
        $this->assertStringContainsString('<x-rfq.public.review', $view);
        $this->assertStringContainsString('<x-rfq.public.summary', $view);
        $this->assertStringContainsString('<x-rfq.public.footer', $view);
    }

    public function test_documents_and_review_steps_preserve_the_supplied_prototype_structure(): void
    {
        $documents = file_get_contents(resource_path('views/components/rfq/public/documents.blade.php'));
        $review = file_get_contents(resource_path('views/components/rfq/public/review.blade.php'));
        $summary = file_get_contents(resource_path('views/components/rfq/public/summary.blade.php'));

        $this->assertStringContainsString('Quotation documents', $documents);
        $this->assertStringContainsString('Required documents', $documents);
        $this->assertStringContainsString('Upload documents', $documents);
        $this->assertStringContainsString('Uploaded files (', $documents);
        $this->assertStringContainsString('Optional supporting information', $documents);
        $this->assertStringContainsString('Continue to review', $documents);

        $this->assertStringContainsString('Review your quotation', $review);
        $this->assertStringContainsString('Supplier and RFQ details', $review);
        $this->assertStringContainsString('Product and pricing', $review);
        $this->assertStringContainsString('Production and delivery', $review);
        $this->assertStringContainsString('Final declaration', $review);
        $this->assertStringContainsString('Submit quotation', $review);

        $this->assertStringContainsString('Quotation summary', $summary);
        $this->assertStringContainsString('Total quoted value', $summary);
        $this->assertStringContainsString('Decline to quote', $summary);
    }

    public function test_supplier_portal_uses_isolated_styles_and_secure_document_routes(): void
    {
        $core = file_get_contents(resource_path('css/application/core.css'));
        $css = file_get_contents(resource_path('css/modules/application/25-public-rfq-quotation-prototype.css'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $portal = file_get_contents(app_path('Services/Inquiries/PublicRfqPortalService.php'));

        $this->assertStringContainsString("@import '../modules/application/25-public-rfq-quotation-prototype.css';", $core);
        $this->assertStringContainsString('.ft-rfq-portal-shell', $css);
        $this->assertStringContainsString('.ft-rfq-summary-card', $css);
        $this->assertStringContainsString('.ft-rfq-review-card', $css);
        $this->assertStringContainsString('.ft-rfq-doc-table', $css);

        $this->assertStringContainsString("->name('rfq.public.documents.upload')", $routes);
        $this->assertStringContainsString("->name('rfq.public.documents.preview')", $routes);
        $this->assertStringContainsString("->name('rfq.public.documents.download')", $routes);
        $this->assertStringContainsString('SecureDocumentStorage::class', $portal);
        $this->assertStringContainsString('documentForInvitation', $portal);
    }

    public function test_product_to_quote_section_matches_the_pricing_prototype_and_uses_project_typography(): void
    {
        $details = file_get_contents(resource_path('views/components/rfq/public/details.blade.php'));
        $pricing = file_get_contents(resource_path('views/components/rfq/public/pricing.blade.php'));
        $productSection = file_get_contents(resource_path('views/components/rfq/public/product-to-quote.blade.php'));
        $css = file_get_contents(resource_path('css/modules/application/25-public-rfq-quotation-prototype.css'));

        $this->assertStringContainsString('<x-rfq.public.product-to-quote', $details);
        $this->assertStringContainsString('<x-rfq.public.product-to-quote', $pricing);
        $this->assertStringContainsString('Product to quote', $productSection);
        $this->assertStringContainsString('Requested quantity', $productSection);
        $this->assertStringContainsString('Buyer requirements', $productSection);
        $this->assertStringContainsString('View specifications', $productSection);
        $this->assertStringContainsString('data-rfq-toggle-requirements', $productSection);

        $this->assertStringContainsString('font-family: var(--ft-theme-font-family)', $css);
        $this->assertStringContainsString('font-size: var(--ft-theme-form-page-title-size)', $css);
        $this->assertStringContainsString('font-size: var(--ft-theme-form-section-title-size)', $css);
        $this->assertStringContainsString('font-size: var(--ft-theme-form-control-size)', $css);
        $this->assertStringNotContainsString('font-size: 9px', $css);
    }

    public function test_submitted_quotation_can_be_reopened_for_revision_while_the_rfq_is_active(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Rfq/PublicInquiryRfqController.php'));
        $rfq = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $portal = file_get_contents(app_path('Services/Inquiries/PublicRfqPortalService.php'));
        $review = file_get_contents(resource_path('views/components/rfq/public/review.blade.php'));
        $summary = file_get_contents(resource_path('views/components/rfq/public/summary.blade.php'));

        $this->assertStringContainsString("\$action === 'revise'", $controller);
        $this->assertStringContainsString('beginQuoteRevision', $rfq);
        $this->assertStringContainsString("'quote_status' => 'draft'", $rfq);
        $this->assertStringContainsString('rfq.quote_revision_started', $rfq);
        $this->assertStringContainsString("'canRevise' => \$canRevise", $portal);
        $this->assertStringContainsString('Revise quotation', $review);
        $this->assertStringContainsString('Revise quotation', $summary);
    }

    public function test_portal_step_builder_captures_the_active_step_without_manual_closure_use(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/PublicRfqPortalService.php'));

        $this->assertStringContainsString("collect(self::STEPS)->map(fn (string $key, int $index): array => [", $service);
        $this->assertStringContainsString("'active' => \$key === \$step", $service);
    }

    public function test_supplier_draft_data_and_documents_are_persisted_before_final_submission(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/PublicRfqPortalService.php'));
        $rfq = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $quote = file_get_contents(app_path('Models/InquiryRfqQuote.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_31_173000_expand_inquiry_rfq_quotes_for_supplier_portal.php'));

        $this->assertStringContainsString('public function saveDetails', $service);
        $this->assertStringContainsString('public function savePricing', $service);
        $this->assertStringContainsString('public function saveDocumentStep', $service);
        $this->assertStringContainsString('public function uploadDocuments', $service);
        $this->assertStringContainsString('public function submitSavedDraft', $rfq);
        $this->assertStringContainsString("'formal_quotation', 'price_breakdown'", $service);
        $this->assertStringContainsString('public function documents(): HasMany', $quote);
        $this->assertStringContainsString("Schema::create('inquiry_rfq_quote_documents'", $migration);
        $this->assertStringContainsString("'supplier_contact_name'", $quote);
        $this->assertStringContainsString("'sample_lead_time_days'", $quote);
    }
}
