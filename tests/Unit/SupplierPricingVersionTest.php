<?php

namespace Tests\Unit;

use App\Services\SupplierBridge\SupplierPricing;
use PHPUnit\Framework\TestCase;

final class SupplierPricingVersionTest extends TestCase
{
    public function test_price_hash_is_independent_of_tier_input_order(): void
    {
        $pricing = new SupplierPricing();
        $a = [['quantity' => 500, 'price' => 1.5], ['quantity' => 100, 'price' => 2.25]];
        $b = array_reverse($a);
        self::assertSame($pricing->version($a), $pricing->version($b));
        self::assertSame(100, $pricing->normalize($a)[0]['quantity']);
    }

    public function test_price_hash_changes_when_a_supplier_tier_changes(): void
    {
        $pricing = new SupplierPricing();
        self::assertNotSame(
            $pricing->version([['quantity' => 100, 'price' => 2.25]]),
            $pricing->version([['quantity' => 100, 'price' => 2.26]]),
        );
    }
}
