<?php

// Dependency-free smoke test of the actual Redo preview calculation.
// The fake empty Order lines keep this test independent of a database.
namespace App\Models {
    class FlowJob
    {
        public int $quantity = 10;
        public float $commercial_value = 1000.00;

        public function items(): RedoSmokeEmptyQuery
        {
            return new RedoSmokeEmptyQuery();
        }
    }

    class RedoSmokeEmptyQuery
    {
        public function active(): self { return $this; }
        public function with(array $relations): self { return $this; }
        public function orderBy(string $column): self { return $this; }
        public function get(array $columns): RedoSmokeEmptyCollection
        {
            return new RedoSmokeEmptyCollection();
        }
    }

    class RedoSmokeEmptyCollection
    {
        public function sum(callable $callback): int { return 0; }
        public function isNotEmpty(): bool { return false; }
    }
}

namespace {
    require_once __DIR__.'/../../app/Services/OrderRedoService.php';

    $service = new \App\Services\OrderRedoService();
    $order = new \App\Models\FlowJob();

    // Both amounts are independent and can be omitted (treated as zero).
    $preview = static function (bool $deduct, float $supplier = 0, float $courier = 0) use ($service, $order): array {
        return $service->financialPreview(
            $order,
            4,
            'free',
            'percent',
            0,
            'percent',
            40,
            $deduct,
            $supplier,
            $courier,
        );
    };
    $check = static function (string $name, float $expected, float|int $actual): void {
        if (abs($expected - $actual) > 0.000001) {
            throw new \RuntimeException("{$name}: expected {$expected}, got {$actual}");
        }
    };

    $supplier = $preview(true, 320, 0);
    $check('supplier only: supplier recovery', 480, $supplier['recovery']);
    $check('supplier only: courier recovery', 0, $supplier['courierRecovery']);
    $check('supplier only: total freight', 320, $supplier['freight']);

    $courier = $preview(true, 0, 320);
    $check('courier only: supplier recovery', 160, $courier['recovery']);
    $check('courier only: courier recovery', 320, $courier['courierRecovery']);
    $check('courier only: order value unchanged', 1000, $courier['adjustedOrderValue']);

    $both = $preview(true, 100, 220);
    $check('both: supplier recovery', 260, $both['recovery']);
    $check('both: courier recovery', 220, $both['courierRecovery']);
    $check('both: supplier freight', 100, $both['supplierFreight']);
    $check('both: combined freight amount', 320, $both['freight']);

    $empty = $preview(true);
    $check('blank amounts: supplier freight not applicable', 0, $empty['supplierFreight']);
    $check('blank amounts: courier freight not applicable', 0, $empty['courierRecovery']);
    $check('blank amounts: no freight deducted', 0, $empty['freight']);
    $check('blank amounts: other supplier adjustment preserved', 160, $empty['recovery']);

    $disabled = $preview(false, 100, 220);
    $check('unchecked: supplier freight ignored', 0, $disabled['supplierFreight']);
    $check('unchecked: courier freight ignored', 0, $disabled['courierRecovery']);
    $check('unchecked: supplier recovery unchanged', 160, $disabled['recovery']);

    echo "PASS: supplier-only, courier-only, both, blank and disabled redo freight allocations.\n";
}
