<?php

namespace App\Livewire\MasterData\Concerns;

use App\Models\Client;
use App\Models\MasterRecord;
use App\Support\Filters\ProductClientOptions;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use App\Services\ProductOptionImageService;
use App\Services\ProductPriceTableParser;
use App\Services\ProductCategoryDeletionService;
use App\Support\MasterColor;
use App\Support\AttachmentUpload;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesProductBulkActions
{
    private function selectedProductsQuery()
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();

        if ($this->selectAllMatchingProducts) {
            return $this->filteredProductsQuery()
                ->when($this->excludedProductIds, fn ($query) => $query->whereNotIn('master_records.id', $this->excludedProductIds));
        }

        return MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->with(['parent', 'creator'])
            ->whereIn('id', collect($this->selectedProductIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all());
    }

    public function toggleProductSelection(int $id): void
    {
        abort_unless($this->group === 'product', 404);
        MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())->ofType('product')->findOrFail($id);

        if ($this->selectAllMatchingProducts) {
            $excluded = collect($this->excludedProductIds)->map(fn ($value) => (int) $value);
            $this->excludedProductIds = $excluded->contains($id)
                ? $excluded->reject(fn ($value) => $value === $id)->values()->all()
                : $excluded->push($id)->unique()->values()->all();
            return;
        }

        $selected = collect($this->selectedProductIds)->map(fn ($value) => (int) $value);
        $this->selectedProductIds = $selected->contains($id)
            ? $selected->reject(fn ($value) => $value === $id)->values()->all()
            : $selected->push($id)->unique()->values()->all();
    }

    public function toggleProductPageSelection(array $ids, bool $checked): void
    {
        abort_unless($this->group === 'product', 404);
        $ids = collect($ids)->map(fn ($value) => (int) $value)->filter()->unique()->values();
        if ($ids->isEmpty()) return;

        $validIds = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->whereIn('id', $ids->all())
            ->pluck('id')->map(fn ($value) => (int) $value);

        if ($this->selectAllMatchingProducts) {
            $excluded = collect($this->excludedProductIds)->map(fn ($value) => (int) $value);
            $this->excludedProductIds = $checked
                ? $excluded->reject(fn ($value) => $validIds->contains($value))->values()->all()
                : $excluded->concat($validIds)->unique()->values()->all();
            return;
        }

        $selected = collect($this->selectedProductIds)->map(fn ($value) => (int) $value);
        $this->selectedProductIds = $checked
            ? $selected->concat($validIds)->unique()->values()->all()
            : $selected->reject(fn ($value) => $validIds->contains($value))->values()->all();
    }

    public function selectAllFilteredProducts(): void
    {
        abort_unless($this->group === 'product', 404);
        $this->selectAllMatchingProducts = true;
        $this->selectedProductIds = [];
        $this->excludedProductIds = [];
    }

    public function clearProductSelection(): void
    {
        $this->selectedProductIds = [];
        $this->excludedProductIds = [];
        $this->selectAllMatchingProducts = false;
        $this->bulkProductPanel = null;
        $this->resetBulkProductActionState();
    }

    private function resetBulkProductActionState(): void
    {
        $this->bulkProductClientMode = 'all';
        $this->bulkProductClientIds = [];
        $this->bulkProductMainCategory = '';
        $this->bulkProductCategoryId = null;
        $this->bulkProductSubcategory = '';
        $this->bulkProductSupplierIds = [];
        $this->resetValidation([
            'bulkProductClientMode', 'bulkProductClientIds', 'bulkProductMainCategory',
            'bulkProductCategoryId', 'bulkProductSubcategory', 'bulkProductSupplierIds',
        ]);
    }

    public function openProductBulkPanel(string $panel): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(in_array($panel, ['clients', 'category'], true), 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);
        abort_if($this->productSelectionCount() < 1, 422, 'Select at least one product.');

        $this->resetBulkProductActionState();
        $this->bulkProductPanel = $panel;
    }

    public function closeProductBulkPanel(): void
    {
        $this->bulkProductPanel = null;
        $this->resetBulkProductActionState();
    }

    public function updatedBulkProductMainCategory(): void
    {
        $this->bulkProductCategoryId = null;
        $this->bulkProductSubcategory = '';
        $this->resetValidation(['bulkProductCategoryId', 'bulkProductSubcategory']);
    }

    public function updatedBulkProductCategoryId(): void
    {
        $this->bulkProductSubcategory = '';
        $this->resetValidation(['bulkProductCategoryId', 'bulkProductSubcategory']);
    }

    public function toggleBulkProductClient(int $clientId): void
    {
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);
        abort_unless(Client::query()->whereKey($clientId)->where('is_active', true)->exists(), 404);
        $ids = collect($this->bulkProductClientIds)->map(fn ($value) => (int) $value);
        $this->bulkProductClientIds = $ids->contains($clientId)
            ? $ids->reject(fn ($value) => $value === $clientId)->values()->all()
            : $ids->push($clientId)->unique()->values()->all();
        $this->bulkProductClientMode = 'specific';
        $this->resetValidation('bulkProductClientIds');
    }

    public function bulkSetProductStatus(string $status): void
    {
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);
        abort_unless(in_array($status, ['active', 'inactive'], true), 422);
        $count = $this->productSelectionCount();
        if ($count < 1) return;

        $this->selectedProductsQuery()->reorder()->update(['status' => $status]);
        app(\App\Services\WorkspaceRefreshService::class)->touch('MasterRecord:bulk-product-status');
        $this->clearProductSelection();
        $this->recordsReady = true;
        session()->flash('success', number_format($count).' '.strtolower(\Illuminate\Support\Str::plural('product', $count)).' set to '.$status.'.');
    }

    public function applyBulkProductClients(): void
    {
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);
        $this->validate([
            'bulkProductClientMode' => ['required', Rule::in(['all', 'specific'])],
            'bulkProductClientIds' => $this->bulkProductClientMode === 'specific' ? ['required', 'array', 'min:1'] : ['array'],
            'bulkProductClientIds.*' => ['integer', Rule::exists('clients', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ]);
        $count = $this->productSelectionCount();
        if ($count < 1) return;

        $clients = $this->bulkProductClientMode === 'specific'
            ? Client::query()->whereIn('id', $this->bulkProductClientIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
            : collect();

        $this->selectedProductsQuery()->select(['id', 'metadata'])->reorder('id')->chunkById(200, function ($products) use ($clients): void {
            foreach ($products as $product) {
                $metadata = (array) ($product->metadata ?? []);
                $metadata['client_availability'] = $this->bulkProductClientMode;
                if ($this->bulkProductClientMode === 'specific') {
                    $metadata['client_ids'] = $clients->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                    $metadata['client_availability_labels'] = $clients->pluck('name')->values()->all();
                    $metadata['client_codes'] = $clients->pluck('code')->filter()->values()->all();
                } else {
                    unset($metadata['client_ids'], $metadata['client_availability_labels'], $metadata['client_codes']);
                }
                $product->metadata = $metadata;
                $product->save();
            }
        });

        $this->bulkProductPanel = null;
        $this->clearProductSelection();
        session()->flash('success', 'Client availability updated for '.number_format($count).' '.strtolower(\Illuminate\Support\Str::plural('product', $count)).'.');
    }

    public function applyBulkProductCategory(): void
    {
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $data = $this->validate([
            'bulkProductMainCategory' => ['required', 'string', 'max:255'],
            'bulkProductCategoryId' => [
                'required', 'integer',
                Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product_category')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'bulkProductSubcategory' => ['nullable', 'string', 'max:255'],
        ]);
        $category = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->active()->findOrFail((int) $data['bulkProductCategoryId']);
        $main = trim((string) (data_get($category->metadata, 'main_category') ?: data_get($category->metadata, 'excel_main_category') ?: $data['bulkProductMainCategory']));
        $subcategory = trim((string) $data['bulkProductSubcategory']);
        if ($subcategory !== '') {
            $knownSubcategory = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_subcategory')
                ->active()
                ->where('parent_id', $category->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($subcategory)])
                ->exists();
            $legacySubcategory = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->where('parent_id', $category->id)
                ->get(['metadata'])
                ->contains(fn (MasterRecord $product) => mb_strtolower(trim((string) (data_get($product->metadata, 'sub_category') ?: data_get($product->metadata, 'excel_sub_category')))) === mb_strtolower($subcategory));
            if (! $knownSubcategory && ! $legacySubcategory) {
                $this->addError('bulkProductSubcategory', 'Select a valid subcategory for the chosen product category.');
                return;
            }
        }
        $count = $this->productSelectionCount();
        if ($count < 1) return;

        $this->selectedProductsQuery()->select(['id', 'metadata'])->reorder('id')->chunkById(200, function ($products) use ($category, $main, $subcategory): void {
            foreach ($products as $product) {
                $metadata = (array) ($product->metadata ?? []);
                $metadata['main_category'] = $main;
                $metadata['excel_main_category'] = $main;
                unset($metadata['taxonomy_unassigned']);
                if ($subcategory !== '') {
                    $metadata['sub_category'] = $subcategory;
                    $metadata['excel_sub_category'] = $subcategory;
                } else {
                    unset($metadata['sub_category'], $metadata['excel_sub_category']);
                }
                $product->parent_id = $category->id;
                $product->metadata = $metadata;
                $product->save();
            }
        });

        $this->bulkProductPanel = null;
        $this->clearProductSelection();
        session()->flash('success', 'Category updated for '.number_format($count).' '.strtolower(\Illuminate\Support\Str::plural('product', $count)).'.');
    }

    public function exportProducts()
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'view'), 403);

        return $this->downloadProducts($this->filteredProductsQuery());
    }

    public function exportSelectedProducts()
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'view'), 403);
        if ($this->productSelectionCount() < 1) return null;

        return $this->downloadProducts($this->selectedProductsQuery());
    }

    private function downloadProducts(\Illuminate\Database\Eloquent\Builder $query)
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $filename = 'flowtracker-products-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($query, $workspaceId): void {
            app(\App\Services\ProductCatalogExportService::class)->write($query, $workspaceId);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function bulkDeleteProducts(): void
    {
        abort_unless(auth()->user()?->canModule('catalog_products', 'delete'), 403);
        $products = $this->selectedProductsQuery()->get(['id', 'name']);
        if ($products->isEmpty()) return;
        $service = app(MasterDataService::class);
        $deleted = 0;
        $blocked = [];
        foreach ($products as $product) {
            try {
                app(\App\Actions\MasterData\DeleteMasterRecordAction::class)->execute($product->id);
                $deleted++;
            } catch (ValidationException $exception) {
                $blocked[] = $product->name;
            }
        }
        $this->clearProductSelection();
        $this->resetPage('masterPage');
        if ($deleted) session()->flash('success', number_format($deleted).' '.strtolower(\Illuminate\Support\Str::plural('product', $deleted)).' deleted.');
        if ($blocked) $this->addError('record', count($blocked).' selected '.\Illuminate\Support\Str::plural('product', count($blocked)).' could not be deleted because they are in use.');
    }

    private function productSelectionCount(): int
    {
        if ($this->group !== 'product') return 0;
        if ($this->selectAllMatchingProducts) {
            return max(0, (int) $this->filteredProductsQuery()->count() - count($this->excludedProductIds));
        }
        return count(collect($this->selectedProductIds)->map(fn ($id) => (int) $id)->filter()->unique());
    }
}
