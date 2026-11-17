<?php

namespace Tests\Unit;

use Tests\TestCase;

final class SupplierCurrencyEditorViewScopeTest extends TestCase
{
    public function test_livewire_currency_editor_state_is_passed_to_both_product_blade_components(): void
    {
        $section = file_get_contents(resource_path('views/livewire/master-data/sections/product.blade.php'));
        $view = file_get_contents(resource_path('views/components/catalog/product-view.blade.php'));
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));

        foreach ([
            'editing-supplier-currency-table' => 'editingSupplierCurrencyTable',
            'currency-table-supplier-id' => 'currencyTableSupplierId',
            'currency-table-currency' => 'currencyTableCurrency',
            'currency-table-original-currency' => 'currencyTableOriginalCurrency',
            'currency-table-version' => 'currencyTableVersion',
            'currency-table-tiers' => 'currencyTableTiers',
        ] as $bladeAttribute => $componentProp) {
            $this->assertSame(2, substr_count($section, ':'.$bladeAttribute.'="$'.$componentProp.'"'),
                'Pass '.$componentProp.' to both the product view and form.');
            $this->assertStringContainsString("'".$componentProp."' =>", $view);
            $this->assertStringContainsString("'".$componentProp."' =>", $form);
        }

        $include = "@include('components.catalog.supplier-currency-editor'";
        $this->assertStringContainsString($include, $view);
        $this->assertStringContainsString($include, $form);
    }
}
