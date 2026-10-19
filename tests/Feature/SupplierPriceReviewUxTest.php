<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Source-level UX regression checks do not require an external Supplier Portal. */
final class SupplierPriceReviewUxTest extends TestCase
{
    public function test_pending_only_list_and_review_modal_with_instant_delivery(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/SupplierBridge/SupplierPriceReviewController.php'));
        $view = file_get_contents(resource_path('views/pages/supplier-price-approvals.blade.php'));
        $sender = file_get_contents(app_path('Services/SupplierBridge/SendCatalogImmediately.php'));

        $this->assertStringContainsString("->where('proposals.status', 'pending')", $controller);
        $this->assertStringNotContainsString("compact('proposals', 'assignments')", $controller);
        $this->assertStringContainsString('View changes', $view);
        $this->assertStringContainsString('data-open-price-review', $view);
        $this->assertStringContainsString('value="approved"', $view);
        $this->assertStringContainsString('value="rejected"', $view);
        $this->assertStringContainsString('new_price', $view);
        $this->assertStringContainsString('SendCatalogImmediately::class', $controller);
        $this->assertStringContainsString('triggerSupplierCatalog', $sender);
    }
}
