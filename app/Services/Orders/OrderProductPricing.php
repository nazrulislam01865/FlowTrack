<?php

namespace App\Services\Orders;

use App\Models\MasterRecord;
use Illuminate\Support\Facades\DB;

/** Pricing snapshots for the selected order lines; supplier costs never replace selling prices. */
final class OrderProductPricing
{
    /**
     * Batch-load supplier-specific costs with the canonical shared supplier table as fallback.
     * A missing tier remains null; no customer price is ever used as a supplier cost.
     *
     * @param array<int,array<string,mixed>> $items
     * @param \Illuminate\Support\Collection<int, MasterRecord> $products keyed by product id
     * @return array<string, float|null> keyed by productId:supplierId
     */
    public function supplierUnitPrices(array $items, $products): array
    {
        $selections = collect($items)->filter(fn ($row) => (int) ($row['supplier_id'] ?? 0) > 0
            && (int) ($row['product_id'] ?? 0) > 0)->values();
        if ($selections->isEmpty()) return [];

        $productIds = $selections->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $supplierIds = $selections->pluck('supplier_id')->map(fn ($id) => (int) $id)->unique()->values();
        $overrides = DB::table('supplier_product_prices')
            ->where('workspace_id', app(\App\Services\MasterDataService::class)->workspaceId())
            ->whereIn('product_id', $productIds)
            ->whereIn('supplier_id', $supplierIds)
            ->get(['product_id', 'supplier_id', 'price_breakpoints'])
            ->keyBy(fn ($row) => $row->product_id.':'.$row->supplier_id);

        // Links can be represented in either the canonical table or older
        // product metadata. Fetch all table links in one query, not per line.
        $linked = DB::table('product_supplier_links')
            ->where('workspace_id', app(\App\Services\MasterDataService::class)->workspaceId())
            ->whereIn('product_id', $productIds)->whereIn('supplier_id', $supplierIds)
            ->get(['product_id', 'supplier_id'])
            ->mapWithKeys(fn ($row) => [$row->product_id.':'.$row->supplier_id => true]);

        $result = [];
        foreach ($selections as $row) {
            $productId = (int) $row['product_id'];
            $supplierId = (int) $row['supplier_id'];
            $key = $productId.':'.$supplierId;
            $product = $products->get($productId);
            if (! $product || (! $product->hasProductSupplier($supplierId) && ! $linked->has($key))) {
                $result[$key] = null;
                continue;
            }
            $raw = $overrides->get($key)?->price_breakpoints;
            // The historic generic supplier table belongs to the Product's
            // default supplier. Never silently lend that cost to supplier B.
            // Products with a single linked supplier retain legacy compatibility.
            $defaultSupplierId = (int) ($product->productSupplierId() ?? 0);
            $legacyFallbackAllowed = $defaultSupplierId === $supplierId
                || ($defaultSupplierId === 0 && count($product->productSupplierIds()) === 1);
            $tiers = $raw !== null
                ? (json_decode((string) $raw, true) ?: [])
                : ($legacyFallbackAllowed ? $product->supplierPriceBreakpoints() : []);
            $price = null;
            usort($tiers, fn ($a, $b) => (int) ($a['quantity'] ?? 0) <=> (int) ($b['quantity'] ?? 0));
            foreach ($tiers as $tier) {
                if ((int) ($tier['quantity'] ?? 0) > (int) ($row['quantity'] ?? 0)) break;
                if ((int) ($tier['quantity'] ?? 0) > 0 && is_numeric($tier['price'] ?? null)) {
                    $price = round((float) $tier['price'], 2);
                }
            }
            $result[$key] = $price;
        }
        return $result;
    }
}
