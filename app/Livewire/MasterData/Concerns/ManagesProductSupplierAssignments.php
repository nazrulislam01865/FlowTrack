<?php

namespace App\Livewire\MasterData\Concerns;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

trait ManagesProductSupplierAssignments
{
    public function openProductSupplierAssignment(): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);
        abort_if($this->productSelectionCount() < 1, 422, 'Select at least one product.');

        $this->bulkProductSupplierIds = [];
        $this->resetValidation('bulkProductSupplierIds');
        $this->bulkProductPanel = 'supplier';
    }

    public function openProductSupplierAssignmentFor(int $productId): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $product = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->findOrFail($productId, ['id']);

        $this->clearProductSelection();
        $this->selectedProductIds = [(int) $product->id];
        $this->bulkProductSupplierIds = [];
        $this->bulkProductPanel = 'supplier';
        $this->resetValidation('bulkProductSupplierIds');
    }

    public function chooseBulkProductSupplier(int $supplierId): void
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $supplier = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId, ['id']);

        $ids = collect($this->bulkProductSupplierIds)->map(fn ($id) => (int) $id);
        $this->bulkProductSupplierIds = ($ids->contains((int) $supplier->id)
            ? $ids->reject(fn (int $id) => $id === (int) $supplier->id)
            : $ids->push((int) $supplier->id))->unique()->values()->all();
        $this->resetValidation('bulkProductSupplierIds');
    }

    public function applyBulkProductSupplier(): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $data = $this->validate([
            'bulkProductSupplierIds' => ['required', 'array', 'min:1', 'max:100'],
            'bulkProductSupplierIds.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'supplier')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
        ], [
            'bulkProductSupplierIds.required' => 'Choose at least one supplier to continue.',
            'bulkProductSupplierIds.min' => 'Choose at least one supplier to continue.',
        ]);

        $count = $this->productSelectionCount();
        if ($count < 1) return;

        $supplierIds = collect($data['bulkProductSupplierIds'])->map(fn ($id) => (int) $id)->unique()->values();
        $newLinks = 0;
        DB::transaction(function () use ($supplierIds, $workspaceId, &$newLinks): void {
            $hasLinksTable = Schema::hasTable('product_supplier_links');
            $this->selectedProductsQuery()
                ->select(['id', 'metadata'])
                ->reorder('id')
                ->chunkById(200, function ($products) use ($supplierIds, $workspaceId, $hasLinksTable, &$newLinks): void {
                    $pivotRows = [];
                    foreach ($products as $product) {
                        $metadata = (array) ($product->metadata ?? []);
                        $linkedIds = collect($product->productSupplierIds())->map(fn ($id) => (int) $id)->filter();
                        $newLinks += $supplierIds->diff($linkedIds)->count();
                        if (! $product->productSupplierId()) {
                            $metadata['supplier_id'] = (int) $supplierIds->first();
                            unset($metadata['default_supplier_id']);
                        }
                        $metadata['supplier_ids'] = $linkedIds->merge($supplierIds)->unique()->values()->all();
                        $product->metadata = $metadata;
                        $product->save();

                        if ($hasLinksTable) {
                            foreach ($supplierIds as $supplierId) {
                                $pivotRows[] = [
                                    'workspace_id' => $workspaceId,
                                    'product_id' => (int) $product->id,
                                    'supplier_id' => (int) $supplierId,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                    }
                    if ($pivotRows !== []) {
                        DB::table('product_supplier_links')->insertOrIgnore($pivotRows);
                    }
                });
        });

        app(\App\Services\SupplierBridge\MarkCatalogDirty::class)->supplierIds($workspaceId, $supplierIds->all());
        app(\App\Services\WorkspaceRefreshService::class)->touch('MasterRecord:bulk-product-supplier');
        $this->bulkProductPanel = null;
        $this->clearProductSelection();
        $this->recordsReady = true;

        session()->flash(
            'success',
            number_format($supplierIds->count()).' suppliers linked to '.number_format($count).' products ('.number_format($newLinks).' new links).'
        );
    }
}
