<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical read-side for the Product catalogue.
 *
 * Product Categories are deliberately never returned from this service. They
 * may only participate as a parent/filter for a Product record. Keeping this
 * in one service prevents Create Order/Inquiry selectors from accidentally
 * falling back to a category master-data source.
 */
class ProductCatalogService
{
    public function workspaceId(): int
    {
        return app(MasterDataService::class)->workspaceId();
    }

    public function activeProductsQuery(): Builder
    {
        // IMPORTANT: use the exact same canonical source as Master Data > Products.
        // In this project Products are stored in master_records with type=product;
        // product_category rows are a different master-data type and can never be
        // returned by this query.
        return app(MasterDataService::class)
            ->query('product', '', ['status' => 'active'])
            ->where('master_records.type', 'product')
            ->where('master_records.status', 'active');
    }

    public function searchForOrderCreation(string $search = '', ?int $categoryId = null, int $limit = 3): Collection
    {
        $search = trim($search);
        $categoryId = max(0, (int) $categoryId);
        $limit = max(1, min(100, $limit));

        return $this->filteredOrderProductQuery($search, $categoryId)
            ->with(['parent' => fn ($parent) => $parent
                ->where('type', 'product_category')
                ->select(['id', 'name', 'status', 'type'])])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'type', 'parent_id', 'name', 'code', 'description', 'metadata', 'status'])
            // Defense in depth: even if this query is changed later, a
            // Product Category can never be handed to the Create Order view.
            ->filter(fn (MasterRecord $record): bool => $record->type === 'product')
            ->values();
    }

    public function orderSearchCount(string $search = '', ?int $categoryId = null): int
    {
        return $this->filteredOrderProductQuery(trim($search), max(0, (int) $categoryId))->count();
    }


    public function mainCategories(): Collection
    {
        $catalogued = MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_main_category')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name');

        $legacy = MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('product')
            ->with('parent:id,metadata,name')
            ->get(['id', 'parent_id', 'metadata'])
            ->map(fn (MasterRecord $product) => $product->productMainCategory());

        return $catalogued
            ->concat($legacy)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => mb_strtolower((string) $value))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function activeCount(): int
    {
        return $this->activeProductsQuery()->count();
    }

    public function selectedProducts(iterable $ids): Collection
    {
        $ids = collect($ids)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) return collect();

        return $this->activeProductsQuery()
            ->whereIn('id', $ids)
            ->with(['parent' => fn ($parent) => $parent
                ->where('type', 'product_category')
                ->select(['id', 'name', 'status', 'type'])])
            ->get(['id', 'type', 'parent_id', 'name', 'code', 'description', 'metadata', 'status'])
            ->filter(fn (MasterRecord $record): bool => $record->type === 'product')
            ->keyBy('id');
    }

    public function findActiveProductOrFail(int $productId): MasterRecord
    {
        return $this->activeProductsQuery()
            ->with(['parent' => fn ($parent) => $parent
                ->where('type', 'product_category')
                ->select(['id', 'name', 'status', 'type'])])
            ->findOrFail($productId);
    }

    /**
     * Return the active Supplier linked to a Product, or null for legacy
     * products that do not yet have a catalogue supplier configured.
     */
    public function supplierForProduct(MasterRecord $product): ?MasterRecord
    {
        $supplierId = $product->productSupplierId();
        if (!$supplierId) return null;

        return MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('supplier')
            ->active()
            ->find($supplierId, ['id', 'name', 'code', 'metadata', 'status']);
    }

    /**
     * Resolve all active Supplier assignments for each Product in one bounded read.
     *
     * The normalized product_supplier_links table is preferred when available,
     * while metadata supplier_ids remains a backward-compatible source for older
     * Product rows. The default supplier is kept first for predictable display.
     *
     * @return Collection<int, Collection<int, MasterRecord>> keyed by Product id
     */
    public function allSuppliersForProducts(Collection $products): Collection
    {
        $products = $products
            ->filter(fn ($product) => $product instanceof MasterRecord && $product->type === 'product')
            ->keyBy(fn (MasterRecord $product): int => (int) $product->id);

        if ($products->isEmpty()) return collect();

        $supplierIdsByProduct = $products->mapWithKeys(function (MasterRecord $product): array {
            $ids = collect($product->productSupplierIds())->map(fn ($id) => (int) $id)->filter();
            $defaultId = $product->productSupplierId();
            if ($defaultId) $ids->prepend((int) $defaultId);

            return [(int) $product->id => $ids->unique()->values()];
        });

        if (Schema::hasTable('product_supplier_links')) {
            DB::table('product_supplier_links')
                ->where('workspace_id', $this->workspaceId())
                ->whereIn('product_id', $products->keys()->all())
                ->orderBy('id')
                ->get(['product_id', 'supplier_id'])
                ->each(function ($link) use ($supplierIdsByProduct): void {
                    $productId = (int) $link->product_id;
                    $supplierId = (int) $link->supplier_id;
                    if ($productId <= 0 || $supplierId <= 0) return;
                    $ids = collect($supplierIdsByProduct->get($productId, collect()));
                    $ids->push($supplierId);
                    $supplierIdsByProduct->put($productId, $ids->unique()->values());
                });
        }

        $supplierIds = $supplierIdsByProduct
            ->flatMap(fn (Collection $ids) => $ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($supplierIds->isEmpty()) {
            return $products->mapWithKeys(fn (MasterRecord $product): array => [(int) $product->id => collect()]);
        }

        $suppliers = MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('supplier')
            ->active()
            ->whereIn('id', $supplierIds->all())
            ->get(['id', 'name', 'code', 'metadata', 'type', 'status'])
            ->keyBy(fn (MasterRecord $supplier): int => (int) $supplier->id);

        return $products->mapWithKeys(function (MasterRecord $product) use ($supplierIdsByProduct, $suppliers): array {
            $ids = collect($supplierIdsByProduct->get((int) $product->id, collect()));
            $defaultId = $product->productSupplierId();
            if ($defaultId) {
                $ids = $ids->reject(fn (int $id): bool => $id === (int) $defaultId)->prepend((int) $defaultId);
            }

            return [(int) $product->id => $ids
                ->unique()
                ->map(fn (int $supplierId) => $suppliers->get($supplierId))
                ->filter()
                ->values()];
        });
    }

    /**
     * Resolve Product => Supplier in one bounded query for Create Order rows.
     *
     * @return Collection<int, MasterRecord> keyed by Product id
     */
    public function suppliersForProducts(Collection $products): Collection
    {
        $productSupplierIds = $products
            ->filter(fn ($product) => $product instanceof MasterRecord && $product->type === 'product')
            ->mapWithKeys(fn (MasterRecord $product) => [$product->id => $product->productSupplierId()])
            ->filter();

        if ($productSupplierIds->isEmpty()) return collect();

        $suppliers = MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('supplier')
            ->active()
            ->whereIn('id', $productSupplierIds->values()->unique()->all())
            ->get(['id', 'name', 'code', 'metadata', 'type', 'status'])
            ->keyBy('id');

        return $productSupplierIds
            ->map(fn ($supplierId) => $suppliers->get((int) $supplierId))
            ->filter();
    }

    /**
     * Resolve the supplier currently selected on create rows, keyed by Product id.
     * This supports an Order-only supplier override without introducing row-level queries.
     *
     * @return Collection<int, MasterRecord>
     */
    public function suppliersForSelectionRows(iterable $rows): Collection
    {
        $productSupplierIds = collect($rows)
            ->filter(fn ($row) => (int) data_get($row, 'product_id', 0) > 0 && (int) data_get($row, 'supplier_id', 0) > 0)
            ->mapWithKeys(fn ($row) => [(int) data_get($row, 'product_id') => (int) data_get($row, 'supplier_id')]);

        if ($productSupplierIds->isEmpty()) return collect();

        $suppliers = MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('supplier')
            ->active()
            ->whereIn('id', $productSupplierIds->values()->unique()->all())
            ->get(['id', 'name', 'code', 'metadata', 'type', 'status'])
            ->keyBy('id');

        return $productSupplierIds
            ->map(fn ($supplierId) => $suppliers->get((int) $supplierId))
            ->filter();
    }

    /**
     * Link an active Supplier to a Product while keeping the normalized pivot and
     * legacy metadata representation synchronized. Existing links are preserved.
     */
    public function assignSupplierToProduct(MasterRecord $product, int $supplierId): void
    {
        abort_unless($product->type === 'product' && (int) $product->id > 0, 422, 'Invalid product.');

        $supplier = MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId, ['id']);

        DB::transaction(function () use ($product, $supplier): void {
            $product = MasterRecord::query()
                ->forWorkspace($this->workspaceId())
                ->ofType('product')
                ->lockForUpdate()
                ->findOrFail((int) $product->id);
            $metadata = (array) ($product->metadata ?? []);
            $supplierIds = collect($product->productSupplierIds())
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->push((int) $supplier->id)
                ->unique()
                ->values();

            if (! $product->productSupplierId()) {
                $metadata['supplier_id'] = (int) $supplier->id;
                unset($metadata['default_supplier_id']);
            }
            $metadata['supplier_ids'] = $supplierIds->all();
            $product->metadata = $metadata;
            $product->save();

            if (Schema::hasTable('product_supplier_links')) {
                DB::table('product_supplier_links')->insertOrIgnore([
                    'workspace_id' => $this->workspaceId(),
                    'product_id' => (int) $product->id,
                    'supplier_id' => (int) $supplier->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        app(WorkspaceRefreshService::class)->touch('MasterRecord:product-supplier-assignment');
    }

    private function filteredOrderProductQuery(string $search, int $categoryId): Builder
    {
        return $this->activeProductsQuery()
            ->when($categoryId > 0, fn (Builder $query) => $query
                ->where('parent_id', $categoryId)
                ->whereHas('parent', fn (Builder $parent) => $parent
                    ->where('type', 'product_category')
                    ->where('status', 'active')))
            // The product search box searches PRODUCTS only. Product Category is
            // intentionally not part of the text search; categories are available
            // exclusively through the separate category filter beside the box.
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($search): void {
                $match->whereLike('master_records.name', '%'.$search.'%')
                    ->orWhereLike('master_records.code', '%'.$search.'%')
                    ->orWhereLike('master_records.metadata->reference_code', '%'.$search.'%');
            }));
    }
}
