<?php

namespace Tests\Unit;

use App\Services\ClientPriceMatrix;
use InvalidArgumentException;
use Tests\TestCase;

class ClientPriceMatrixTest extends TestCase
{
    public function test_empty_grid_keeps_one_placeholder_but_does_not_save_price(): void
    {
        $matrix = new ClientPriceMatrix();
        $tiers = $matrix->editableTiers([]);
        $this->assertSame([['quantity' => '', 'price' => '']], $tiers);
        $this->assertSame([], $matrix->parseEditableTiers($tiers));
        $this->assertSame('', $matrix->toLegacyTable([]));
    }

    public function test_existing_prices_edit_and_save_in_the_same_legacy_format(): void
    {
        $matrix = new ClientPriceMatrix();
        $existing = [['quantity' => 500, 'price' => 1.75], ['quantity' => 100, 'price' => 2.5]];
        $parsed = $matrix->parseEditableTiers($matrix->editableTiers($existing));
        $this->assertSame([['quantity' => 100, 'price' => 2.5], ['quantity' => 500, 'price' => 1.75]], $parsed);
        $table = $matrix->toLegacyTable($parsed);
        $this->assertSame("Quantity\t100\t500\nProduct price\t2.5\t1.75", $table);
        $this->assertSame($parsed, app(\App\Services\ProductPriceTableParser::class)->parseTable($table)['price_breakpoints']);
    }

    public function test_zero_is_an_explicit_valid_price_and_blank_columns_are_ignored(): void
    {
        $matrix = new ClientPriceMatrix();
        $this->assertSame([['quantity' => 10, 'price' => 0.0]], $matrix->parseEditableTiers([
            ['quantity' => '', 'price' => ''],
            ['quantity' => '10', 'price' => '0'],
        ]));
    }

    public function test_partial_and_duplicate_columns_are_rejected(): void
    {
        $matrix = new ClientPriceMatrix();
        foreach ([
            [['quantity' => '100', 'price' => '']],
            [['quantity' => '', 'price' => '4.25']],
            [['quantity' => '100', 'price' => '-1']],
            [['quantity' => '100', 'price' => '2'], ['quantity' => '100', 'price' => '3']],
        ] as $invalid) {
            try {
                $matrix->parseEditableTiers($invalid);
                $this->fail('Expected invalid client price tier to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_only_client_grid_is_changed_not_supplier_or_remote_area_editors(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $this->assertStringContainsString('wire:click="addClientPriceTier(', $form);
        $this->assertStringContainsString('wire:click="addCustomerPriceTier"', $form);
        $this->assertStringContainsString('wire:model.blur="productRemoteSurchargeTable"', $form);
        $this->assertStringContainsString('wire:click="addSupplierPriceTier(', $form);
        $this->assertStringNotContainsString('wire:model.change="productCustomerPriceTable"', $form);
    }
}
