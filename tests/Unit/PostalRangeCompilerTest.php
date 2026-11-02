<?php

namespace Tests\Unit;

use App\Services\RemoteAreas\PostalRangeCompiler as C;
use PHPUnit\Framework\TestCase;

class PostalRangeCompilerTest extends TestCase
{
    public function test_exact_normalization_and_shape_preserve_identifiers(): void
    {
        self::assertSame('SW1A1AA', C::key(' sw1a  1aa '));
        self::assertSame('DDDDD', C::shape('00000'));
        self::assertSame('LLDLDLL', C::shape('SW1A1AA'));
        self::assertNull(C::shape('SW-1'));
        self::assertSame('AB200', C::successor('AB199', 'LLDDD'));
        self::assertSame('AB199', C::predecessor('AB200', 'LLDDD'));
        self::assertNull(C::successor('ZZ999', 'LLDDD'));
        self::assertNull(C::predecessor('AA000', 'LLDDD'));
    }

    public function test_earlier_rules_win_overlapping_ranges_and_bounds_are_inclusive(): void
    {
        $compiled = C::compile([
            ['from' => '10000', 'to' => '19999', 'id' => 10, 'ordinal' => 0],
            ['from' => '15000', 'to' => '24999', 'id' => 20, 'ordinal' => 1],
            ['from' => '18000', 'to' => '18050', 'id' => 30, 'ordinal' => 2],
            ['from' => 'AB100', 'to' => 'AB199', 'id' => 40, 'ordinal' => 3],
            ['from' => 'ZZ999', 'to' => 'AA000', 'id' => 50, 'ordinal' => 4],
        ]);
        foreach (['10000', '15000', '18025', '19999'] as $code) self::assertSame(10, C::find($compiled, $code));
        foreach (['20000', '24999'] as $code) self::assertSame(20, C::find($compiled, $code));
        foreach (['09999', '25000', 'AC150', 'AB200', 'AA000'] as $code) self::assertNull(C::find($compiled, $code));
        self::assertSame(40, C::find($compiled, 'AB150'));
    }

    public function test_compiled_ranges_match_legacy_first_winner_on_random_samples(): void
    {
        mt_srand(20261102);
        $rules = [];
        for ($i = 0; $i < 200; $i++) {
            $from = mt_rand(0, 9500);
            $to = mt_rand($from, 9999);
            $rules[] = [
                'from' => sprintf('%05d', $from),
                'to' => sprintf('%05d', $to),
                'id' => $i + 1,
                'ordinal' => $i,
            ];
        }
        $compiled = C::compile($rules);
        for ($n = 0; $n < 10000; $n++) {
            $code = sprintf('%05d', mt_rand(0, 9999));
            $expected = null;
            foreach ($rules as $rule) {
                if ($code >= $rule['from'] && $code <= $rule['to']) {
                    $expected = $rule['id'];
                    break;
                }
            }
            self::assertSame($expected, C::find($compiled, $code), $code);
        }
    }
}
