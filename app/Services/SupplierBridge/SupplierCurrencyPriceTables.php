<?php

namespace App\Services\SupplierBridge;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Extra currency-specific supplier price tables; never replaces the approved primary matrix. */
final class SupplierCurrencyPriceTables
{
    public function __construct(
        private readonly SupplierPricing $pricing,
        private readonly SupplierPriceMatrix $matrix,
    ) {}

    /** One batched lookup for a product and its visible suppliers. */
    public function forProduct(MasterRecord $product, array $supplierIds): array
    {
        if ($supplierIds === []) return [];
        return DB::table('supplier_product_currency_tables')
            ->where('workspace_id', $product->workspace_id)
            ->where('product_id', $product->id)
            ->whereIn('supplier_id', array_unique(array_map('intval', $supplierIds)))
            ->orderBy('currency')
            ->get(['supplier_id', 'currency', 'price_breakpoints', 'additional_charges'])
            ->map(function ($row): array {
                $prices = $this->pricing->normalize(json_decode((string) $row->price_breakpoints, true) ?: []);
                $charges = $this->matrix->normalizeCharges(json_decode((string) $row->additional_charges, true) ?: []);
                return [
                    'supplier_id' => (int) $row->supplier_id,
                    'currency' => (string) $row->currency,
                    'rows' => $prices,
                    'charges' => $charges,
                    'version' => $this->version($prices, $charges),
                ];
            })->all();
    }

    public function version(array $prices, array $charges): string
    {
        return hash('sha256', json_encode([
            $this->pricing->normalize($prices), $this->matrix->normalizeCharges($charges),
        ], JSON_THROW_ON_ERROR));
    }

    public function currencyOptions(int $workspace): array
    {
        return MasterRecord::query()->forWorkspace($workspace)->ofType('currency')->active()
            ->orderBy('name')->get(['code', 'name', 'metadata'])
            ->map(fn (MasterRecord $currency): array => [
                'code' => app(MasterDataService::class)->currencyValue($currency),
                'name' => (string) $currency->name,
            ])->filter(fn (array $currency): bool => $currency['code'] !== '')
            ->unique('code')->values()->all();
    }

    /** Serialized on the product row, with an optimistic table version. */
    public function save(MasterRecord $product, int $supplierId, string $currency, array $tiers, string $baseVersion, bool $remove = false): void
    {
        $currency = strtoupper(trim($currency));
        $workspace = (int) $product->workspace_id;
        if (! in_array($currency, array_column($this->currencyOptions($workspace), 'code'), true)) {
            throw ValidationException::withMessages(['currency' => 'Select an active currency from Financial Master Data.']);
        }
        DB::transaction(function () use ($product, $supplierId, $currency, $tiers, $baseVersion, $remove, $workspace): void {
            $locked = MasterRecord::query()->forWorkspace($workspace)->ofType('product')
                ->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->pricing->assigned($locked, $supplierId), 403, 'Supplier is not tagged to this product.');
            abort_if($currency === $locked->supplierPriceCurrency($supplierId), 422,
                'This is the primary supplier currency. Edit its existing approved price table instead.');
            $query = DB::table('supplier_product_currency_tables')
                ->where('workspace_id', $workspace)->where('product_id', $locked->id)
                ->where('supplier_id', $supplierId)->where('currency', $currency);
            $current = $query->first(['price_breakpoints', 'additional_charges']);
            $oldPrices = $current ? $this->pricing->normalize(json_decode((string) $current->price_breakpoints, true) ?: []) : [];
            $oldCharges = $current ? $this->matrix->normalizeCharges(json_decode((string) $current->additional_charges, true) ?: []) : [];
            abort_unless(hash_equals($this->version($oldPrices, $oldCharges), $baseVersion), 409,
                'This currency price table changed. Reload the product before saving.');
            if ($remove) {
                abort_unless($current !== null, 404, 'Currency table not found.');
                $query->delete();
            } else {
                $parsed = $this->matrix->parseEditableTiers($tiers);
                if ($parsed['rows'] === []) {
                    throw ValidationException::withMessages(['tiers' => 'Enter at least one quantity and supplier price.']);
                }
                abort_if($current === null && DB::table('supplier_product_currency_tables')
                    ->where('workspace_id', $workspace)->where('product_id', $locked->id)
                    ->where('supplier_id', $supplierId)->count() >= 20, 422,
                    'Maximum 20 additional currency tables per supplier and product.');
                DB::table('supplier_product_currency_tables')->upsert([[
                    'workspace_id' => $workspace, 'product_id' => $locked->id,
                    'supplier_id' => $supplierId, 'currency' => $currency,
                    'price_breakpoints' => json_encode($parsed['rows'], JSON_THROW_ON_ERROR),
                    'additional_charges' => json_encode($parsed['charges'], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]], ['product_id', 'supplier_id', 'currency'],
                    ['price_breakpoints', 'additional_charges', 'updated_at']);
            }
            app(MarkCatalogDirty::class)->supplierIds($workspace, [$supplierId]);
        });
    }
}
