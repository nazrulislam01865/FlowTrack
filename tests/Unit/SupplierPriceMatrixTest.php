<?php

namespace Tests\Unit;

use App\Services\SupplierBridge\SupplierPriceMatrix;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SupplierPriceMatrixTest extends TestCase
{
    public function test_unpriced_supplier_has_one_empty_editable_column(): void
    {
        $matrix = new SupplierPriceMatrix();
        $this->assertSame([$matrix->blankTier()], $matrix->editableTiers([], []));
        $this->assertSame(['rows' => [], 'charges' => []], $matrix->parseEditableTiers([$matrix->blankTier()]));
    }

    public function test_existing_prices_and_optional_charges_are_round_tripped_by_quantity(): void
    {
        $matrix = new SupplierPriceMatrix();
        $prices = [['quantity' => 100, 'price' => 1.43], ['quantity' => 50, 'price' => 1.73]];
        $charges = [['quantity' => 100, 'urgent_shipment' => 0, 'remote_surcharge' => 0.25],
            ['quantity' => 50, 'standard_shipment' => 2.5]];
        $tiers = $matrix->editableTiers($prices, $charges);

        $this->assertSame('0', $tiers[0]['urgent_shipment']);
        $this->assertSame('', $tiers[0]['standard_shipment']);
        $this->assertSame('2.5', $tiers[1]['standard_shipment']);
        $this->assertSame([
            'rows' => [['quantity' => 50, 'price' => 1.73], ['quantity' => 100, 'price' => 1.43]],
            'charges' => [['quantity' => 50, 'standard_shipment' => 2.5],
                ['quantity' => 100, 'remote_surcharge' => 0.25, 'urgent_shipment' => 0.0]],
        ], $matrix->parseEditableTiers($tiers));
    }

    public function test_only_charges_can_change_without_modifying_price_tiers(): void
    {
        $matrix = new SupplierPriceMatrix();
        $tiers = $matrix->editableTiers([['quantity' => 100, 'price' => 4.50]], []);
        $tiers[0]['standard_shipment'] = '0';
        $tiers[0]['urgent_shipment'] = '5.25';
        $edited = $matrix->parseEditableTiers($tiers);

        $this->assertSame([['quantity' => 100, 'price' => 4.5]], $edited['rows']);
        $this->assertSame([['quantity' => 100, 'standard_shipment' => 0.0, 'urgent_shipment' => 5.25]], $edited['charges']);
        $this->assertNotSame($matrix->chargesVersion([]), $matrix->chargesVersion($edited['charges']));
    }

    public function test_rejects_partially_filled_quantity_columns_and_duplicates(): void
    {
        $matrix = new SupplierPriceMatrix();
        foreach ([
            [['quantity' => '100', 'price' => '', 'remote_surcharge' => '1']],
            [['quantity' => '', 'price' => '1.5']],
            [['quantity' => '50', 'price' => '1'], ['quantity' => '50', 'price' => '2']],
            [['quantity' => '10', 'price' => '1', 'urgent_shipment' => '-2']],
        ] as $tiers) {
            try {
                $matrix->parseEditableTiers($tiers);
                $this->fail('The matrix must reject incomplete, duplicate or invalid rows.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('productSupplierPrices', $exception->errors());
            }
        }
    }
}
