<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Regression checks for the all-client pricing editor and supplier conflict recovery. */
final class ProductPricingAllClientsAndSupplierRefreshTest extends TestCase
{
    public function test_all_clients_has_one_shared_price_card_and_existing_save_field(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));

        $this->assertStringContainsString("$".'clientAvailabilityMode === \'specific\' ? $visibleClientPriceRows->count() : 1', $form);
        $this->assertStringContainsString('<strong>All clients</strong>', $form);
        $this->assertStringContainsString('wire:model.change="productCustomerPriceTable"', $form);
        $this->assertStringContainsString('@if(count($customerPricePreview))', $form);
        $this->assertStringContainsString('wire:model.change="productClientPrices.', $form);
        $this->assertStringContainsString("$"."metadata['customer_price_table_raw']", $editor);
        $this->assertStringContainsString("$"."metadata['customer_price_breakpoints']", $editor);
    }

    public function test_supplier_price_conflicts_have_safe_refresh_without_disabling_concurrency_protection(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));

        $this->assertStringContainsString('wire:click="refreshProductSupplierPrices"', $form);
        $this->assertStringContainsString('wire:confirm="Reload current supplier prices?', $form);
        $this->assertStringContainsString('public function refreshProductSupplierPrices()', $editor);
        $this->assertStringContainsString('if ($rows === $edit[\'rows\'])', $editor);
        $this->assertStringContainsString('hash_equals($pricing->version($rows), $edit[\'base_version\'])', $editor);
        $this->assertStringContainsString('lockForUpdate()', $editor);
        $this->assertStringContainsString('foreach ($supplierEditsToApply as $edit)', $editor);
    }
}
