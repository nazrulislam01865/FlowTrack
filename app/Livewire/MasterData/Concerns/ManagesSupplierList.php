<?php

namespace App\Livewire\MasterData\Concerns;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ManagesSupplierList
{
    public string $supplierStatus = '';

    public function updatedSupplierStatus(): void
    {
        $this->recordsReady = true;
        $this->resetPage('masterPage');
    }

    /**
     * Dedicated supplier query used by the prototype supplier-list screen.
     * Search includes supplier identity/contact data and linked product codes.
     */
    protected function supplierListRows(int $workspaceId): LengthAwarePaginator
    {
        $needle = trim($this->search);
        $linkedSupplierIds = collect();

        if ($needle !== '') {
            $matchingProducts = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->where(function ($query) use ($needle): void {
                    $query->whereLike('code', "%{$needle}%")
                        ->orWhereLike('name', "%{$needle}%")
                        ->orWhereLike('metadata->reference_code', "%{$needle}%");
                })
                ->get(['id', 'metadata']);

            $linkedSupplierIds = $matchingProducts
                ->flatMap(fn (MasterRecord $product) => $product->productSupplierIds())
                ->filter();

            if (Schema::hasTable('product_supplier_links') && $matchingProducts->isNotEmpty()) {
                $linkedSupplierIds = $linkedSupplierIds->concat(
                    DB::table('product_supplier_links')
                        ->where('workspace_id', $workspaceId)
                        ->whereIn('product_id', $matchingProducts->pluck('id')->all())
                        ->pluck('supplier_id')
                );
            }

            $linkedSupplierIds = $linkedSupplierIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
        }

        return MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->when($needle !== '', function ($query) use ($needle, $linkedSupplierIds): void {
                $query->where(function ($match) use ($needle, $linkedSupplierIds): void {
                    $match->whereLike('code', "%{$needle}%")
                        ->orWhereLike('name', "%{$needle}%")
                        ->orWhereLike('description', "%{$needle}%")
                        ->orWhereLike('metadata->contact_person', "%{$needle}%")
                        ->orWhereLike('metadata->email', "%{$needle}%");

                    if ($linkedSupplierIds->isNotEmpty()) {
                        $match->orWhereIn('id', $linkedSupplierIds->all());
                    }
                });
            })
            ->when(in_array($this->supplierStatus, ['active', 'inactive'], true), fn ($query) => $query->where('status', $this->supplierStatus))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(30, ['*'], 'masterPage');
    }

    /**
     * Build counts and product chips for the supplier-list page without N+1 queries.
     *
     * The normalized product_supplier_links table is authoritative when present.
     * Legacy Product metadata and any older reverse Supplier tags are merged in so
     * existing installations show the real count immediately after deployment.
     *
     * @return array{active_suppliers:int,total_products:int,assigned_products:int,unassigned_products:int,products_by_supplier:Collection}
     */
    protected function supplierListSummary(int $workspaceId, ?LengthAwarePaginator $rows): array
    {
        $products = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'metadata']);

        $suppliers = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->get(['id', 'code', 'name', 'status', 'metadata']);

        $validSupplierIds = $suppliers->pluck('id')->map(fn ($id) => (int) $id)->filter()->flip();
        $visibleSupplierIds = collect($rows?->items() ?? [])->pluck('id')->map(fn ($id) => (int) $id)->filter()->flip();
        $productById = $products->keyBy(fn (MasterRecord $product) => (int) $product->id);
        $productByCode = collect();
        foreach ($products as $product) {
            foreach (array_filter([
                strtoupper(trim((string) $product->code)),
                strtoupper($product->productDisplayCode()),
                'PRD-'.(int) $product->id,
            ]) as $code) {
                $productByCode->put($code, $product);
            }
        }

        /** @var Collection<int,Collection<int,int>> $supplierIdsByProduct */
        $supplierIdsByProduct = collect();
        foreach ($products as $product) {
            $supplierIdsByProduct->put(
                (int) $product->id,
                collect($product->productSupplierIds())
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $supplierId) => $validSupplierIds->has($supplierId))
                    ->unique()
                    ->values()
            );
        }

        if (Schema::hasTable('product_supplier_links')) {
            DB::table('product_supplier_links')
                ->where('workspace_id', $workspaceId)
                ->orderBy('id')
                ->get(['product_id', 'supplier_id'])
                ->each(function ($link) use ($supplierIdsByProduct, $productById, $validSupplierIds): void {
                    $productId = (int) $link->product_id;
                    $supplierId = (int) $link->supplier_id;
                    if (! $productById->has($productId) || ! $validSupplierIds->has($supplierId)) return;
                    $bucket = collect($supplierIdsByProduct->get($productId, collect()))->push($supplierId)->unique()->values();
                    $supplierIdsByProduct->put($productId, $bucket);
                });
        }

        // Some older/manual records kept product tags on Supplier metadata instead
        // of Product metadata. Merge those reverse tags instead of reporting zero.
        foreach ($suppliers as $supplier) {
            $rawIds = data_get($supplier->metadata, 'product_ids', data_get($supplier->metadata, 'assigned_product_ids', []));
            foreach ($this->normaliseSupplierProductIds($rawIds) as $productId) {
                if (! $productById->has($productId)) continue;
                $bucket = collect($supplierIdsByProduct->get($productId, collect()))->push((int) $supplier->id)->unique()->values();
                $supplierIdsByProduct->put($productId, $bucket);
            }

            $rawCodes = data_get($supplier->metadata, 'product_codes', data_get($supplier->metadata, 'assigned_product_codes', []));
            $codes = is_array($rawCodes)
                ? $rawCodes
                : (preg_split('/[\s,;|]+/', trim((string) $rawCodes), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            foreach ($codes as $code) {
                $product = $productByCode->get(strtoupper(trim((string) $code)));
                if (! $product) continue;
                $productId = (int) $product->id;
                $bucket = collect($supplierIdsByProduct->get($productId, collect()))->push((int) $supplier->id)->unique()->values();
                $supplierIdsByProduct->put($productId, $bucket);
            }
        }

        $productsBySupplier = collect();
        $assignedProducts = 0;
        foreach ($products as $product) {
            $links = collect($supplierIdsByProduct->get((int) $product->id, collect()))->unique()->values();
            if ($links->isNotEmpty()) $assignedProducts++;

            foreach ($links as $supplierId) {
                if (! $visibleSupplierIds->has((int) $supplierId)) continue;
                $bucket = collect($productsBySupplier->get((int) $supplierId, collect()));
                $bucket->push($product);
                $productsBySupplier->put((int) $supplierId, $bucket->unique('id')->values());
            }
        }

        $totalProducts = $products->count();

        return [
            'active_suppliers' => $suppliers->where('status', 'active')->count(),
            'total_products' => $totalProducts,
            'assigned_products' => $assignedProducts,
            'unassigned_products' => max(0, $totalProducts - $assignedProducts),
            'products_by_supplier' => $productsBySupplier,
        ];
    }

    /** @return array<int,int> */
    private function normaliseSupplierProductIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode(trim($raw), true);
            $raw = is_array($decoded)
                ? $decoded
                : (preg_split('/[\s,;|]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        } elseif (is_numeric($raw)) {
            $raw = [$raw];
        } elseif (! is_array($raw)) {
            $raw = [];
        }

        return collect($raw)
            ->flatten()
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function exportSuppliers(): StreamedResponse
    {
        abort_unless($this->group === 'supplier', 404);
        $this->authorizeGroupAction('view', 'supplier');

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $suppliers = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $supplierIds = $suppliers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $supplierIdSet = collect($supplierIds)->flip();
        $links = collect();

        if ($supplierIds !== []) {
            MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->get(['id', 'metadata'])
                ->each(function (MasterRecord $product) use ($links, $supplierIdSet): void {
                    foreach ($product->productSupplierIds() as $supplierId) {
                        $supplierId = (int) $supplierId;
                        if ($supplierIdSet->has($supplierId)) {
                            $links->put((int) $product->id.':'.$supplierId, $supplierId);
                        }
                    }
                });

            if (Schema::hasTable('product_supplier_links')) {
                DB::table('product_supplier_links')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('supplier_id', $supplierIds)
                    ->get(['product_id', 'supplier_id'])
                    ->each(function ($link) use ($links): void {
                        $supplierId = (int) $link->supplier_id;
                        $links->put((int) $link->product_id.':'.$supplierId, $supplierId);
                    });
            }
        }

        $productCounts = $links->values()->countBy();

        return response()->streamDownload(function () use ($suppliers, $productCounts): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Supplier', 'Contact person', 'Email', 'Phone', 'Products', 'Status', 'Updated'], ',', '"', '');

            foreach ($suppliers as $supplier) {
                fputcsv($handle, [
                    $supplier->name,
                    (string) data_get($supplier->metadata, 'contact_person', ''),
                    (string) data_get($supplier->metadata, 'email', ''),
                    (string) data_get($supplier->metadata, 'phone', ''),
                    (int) ($productCounts[(int) $supplier->id] ?? 0),
                    ucfirst((string) $supplier->status),
                    optional($supplier->updated_at)->toDateTimeString(),
                ], ',', '"', '');
            }

            fclose($handle);
        }, 'suppliers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
