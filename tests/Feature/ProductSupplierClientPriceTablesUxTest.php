<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Prevent approved supplier pricing being hidden by the legacy shared product metadata. */
final class ProductSupplierClientPriceTablesUxTest extends TestCase
{
    public function test_product_pricing_reads_supplier_overrides_in_editor_and_detail(): void
    {
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));
        $renderer = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $detail = file_get_contents(resource_path('views/components/catalog/product-view.blade.php'));

        $this->assertStringContainsString("DB::table('supplier_product_prices')", $editor);
        $this->assertStringContainsString("->pluck('price_breakpoints', 'supplier_id')", $editor);
        $this->assertStringContainsString("->pluck('price_breakpoints', 'supplier_id')", $renderer);
        $this->assertStringContainsString('productSupplierPrices.', $form);
        $this->assertStringContainsString('supplierPricingTables', $detail);
        $this->assertStringNotContainsString('Default Client price table', $form);
        $this->assertStringNotContainsString('Default Client pricing', $detail);
    }

    public function test_product_edits_do_not_overwrite_untouched_supplier_approvals(): void
    {
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));
        $review = file_get_contents(app_path('Http/Controllers/SupplierBridge/SupplierPriceReviewController.php'));

        $this->assertStringContainsString('if ($parsed === $pricing->normalize($original)) continue;', $editor);
        $this->assertStringContainsString('hash_equals($pricing->version($rows), $edit[\'base_version\'])', $editor);
        $this->assertStringContainsString("->where('status', 'pending')->update([", $editor);
        $this->assertStringContainsString("DB::table('supplier_product_prices')->upsert", $review);
    }

    public function test_selected_clients_gain_individual_price_cards_without_dummy_values(): void
    {
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));

        $this->assertStringContainsString('updatedProductClientIds(): void', $editor);
        $this->assertStringContainsString('syncProductClientPriceRowsForSelectedClients()', $editor);
        $this->assertStringContainsString("if ($table === '') continue; // Show empty slots", $editor);
        $this->assertStringContainsString('Client price tables (', $form);
        $this->assertStringContainsString('visibleClientPriceRows', $form);
        $this->assertStringNotContainsString('addProductClientPrice', $form);
        $this->assertStringNotContainsString('removeProductClientPrice', $form);
        $this->assertStringContainsString('supplierPriceBreakpoints()', $editor);
    }
}
