<?php

namespace App\Services\SupplierBridge;

use App\Models\MasterRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Prices are supplier-scoped. Customer selling tables are never read here. */
final class SupplierPricing
{
    public function rows(MasterRecord $product, int $supplierId): array
    {
        $override = DB::table('supplier_product_prices')
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)->where('supplier_id', $supplierId)
            ->value('price_breakpoints');

        // Price-proposal baselines must match the supplier-only catalogue. An
        // unconfigured supplier has no approved tiers, not a product fallback.
        return $override !== null
            ? $this->normalize(json_decode((string) $override, true) ?: [])
            : [];
    }

    /** @param array<mixed> $rows */
    public function normalize(array $rows): array
    {
        if (count($rows) > 100) throw ValidationException::withMessages(['prices' => 'Maximum 100 quantity tiers.']);
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['quantity'], $row['price']) ||
                ! filter_var($row['quantity'], FILTER_VALIDATE_INT) || (int) $row['quantity'] < 1 || (int) $row['quantity'] > 100000000 ||
                ! is_numeric($row['price']) || ! is_finite((float) $row['price']) ||
                (float) $row['price'] < 0 || (float) $row['price'] > 999999999.99) {
                throw ValidationException::withMessages(['prices' => 'Invalid quantity tier or unit price.']);
            }
            $quantity = (int) $row['quantity'];
            if (isset($result[$quantity])) throw ValidationException::withMessages(['prices' => 'Duplicate quantity tier.']);
            $result[$quantity] = ['quantity' => $quantity, 'price' => (float) $row['price']];
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    public function version(array $rows): string
    {
        return hash('sha256', json_encode($this->normalize($rows), JSON_THROW_ON_ERROR));
    }

    public function assigned(MasterRecord $product, int $supplierId): bool
    {
        if ($product->type !== 'product' || $product->trashed()) return false;
        return DB::table('product_supplier_links')->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)->where('supplier_id', $supplierId)->exists();
    }
}
