<?php

namespace Tests\Unit;

use App\Services\ProductPriceTableParser;
use PHPUnit\Framework\TestCase;

final class ProductPriceTableParserSpaceSeparatedTest extends TestCase
{
    public function test_horizontal_price_tables_have_three_separate_tiers(): void
    {
        $expected = [
            ['quantity' => 10, 'price' => 2.99],
            ['quantity' => 20, 'price' => 33.0],
            ['quantity' => 30, 'price' => 34.0],
        ];

        foreach ([
            "Quantity 10 20 30\nProduct price 2.99 33 34",
            "Quantity\t10\t20\t30\nProduct price\t2.99\t33\t34",
            "Quantity\t10 20 30\nProduct price\t2.99 33 34",
        ] as $table) {
            self::assertSame($expected, (new ProductPriceTableParser())->parse($table));
        }
    }

    public function test_vertical_input_remains_supported(): void
    {
        self::assertSame([
            ['quantity' => 10, 'price' => 2.99],
            ['quantity' => 20, 'price' => 33.0],
        ], (new ProductPriceTableParser())->parse("Quantity\tProduct price\n10\t2.99\n20\t33"));
    }
}
