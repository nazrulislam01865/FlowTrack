<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SupplierPricingMatrixSourceTest extends TestCase
{
    public function test_supplier_edit_and_details_use_the_same_quantity_charge_matrix(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $view = file_get_contents(resource_path('views/components/catalog/product-view.blade.php'));
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));
        $loader = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));

        foreach (['price', 'remote_surcharge', 'standard_shipment', 'urgent_shipment'] as $field) {
            $this->assertStringContainsString("'$field' =>", $form);
            $this->assertStringContainsString("'$field' =>", $view);
        }
        $this->assertStringContainsString('addSupplierPriceTier', $form);
        $this->assertStringContainsString('removeSupplierPriceTier', $form);
        $this->assertStringNotContainsString('wire:model.change="productSupplierPrices.', $form);
        $this->assertStringContainsString('@empty', $view);
        $this->assertStringContainsString('additional_charges', $editor);
        $this->assertStringContainsString('additional_charges', $loader);
        $this->assertStringContainsString("if (! \$edit['price_changed']) continue", $editor);
        $this->assertStringContainsString('hash_equals($pricing->version($rows)', $editor);
    }
}
