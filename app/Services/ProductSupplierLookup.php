<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolves current supplier links plus the legacy product metadata in batches. */
class ProductSupplierLookup
{
    /** @param Collection<int, MasterRecord> $products
     *  @return Collection<int, Collection<int, MasterRecord>>
     */
    public function forProducts(Collection $products, int $workspaceId, ?bool $hasLinksTable = null): Collection
    {
        if ($products->isEmpty()) return collect();

        $idsByProduct = $products->mapWithKeys(fn (MasterRecord $product) => [
            (int) $product->id => collect($product->productSupplierIds())
                ->map(fn ($id) => (int) $id)->filter()->unique()->values(),
        ]);

        if ($hasLinksTable ?? Schema::hasTable('product_supplier_links')) {
            DB::table('product_supplier_links')
                ->where('workspace_id', $workspaceId)
                ->whereIn('product_id', $products->pluck('id')->all())
                ->get(['product_id', 'supplier_id'])
                ->each(function ($link) use ($idsByProduct): void {
                    $id = (int) $link->product_id;
                    $idsByProduct->get($id)->push((int) $link->supplier_id);
                });
        }

        $allIds = $idsByProduct->flatten()->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $suppliers = $allIds->isEmpty() ? collect() : MasterRecord::query()
            ->forWorkspace($workspaceId)->ofType('supplier')
            ->whereIn('id', $allIds->all())
            ->get(['id', 'name', 'code', 'metadata', 'status'])
            ->keyBy('id');

        return $products->mapWithKeys(fn (MasterRecord $product) => [
            (int) $product->id => $idsByProduct->get((int) $product->id, collect())
                ->unique()
                ->map(fn ($id) => $suppliers->get((int) $id))
                ->filter()
                ->values(),
        ]);
    }
}
