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

trait ManagesProductListFilters
{
    public function updatedSearch(): void
    {
        $this->recordsReady = true;
        $this->clearProductSelection();
        if ($this->group === 'product_category') {
            $this->clearCategorySelection();
            $this->resetCategoryLazyLoading();
        }
        $this->resetPage('masterPage');
    }

    public function updatedProductMainCategory(): void
    {
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function updatedProductCategory(): void
    {
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function updatedProductClientAvailability(): void
    {
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function updatedProductStatus(): void
    {
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function updatedProductSupplierFilterId(): void
    {
        $value = trim($this->productSupplierFilterId);
        $this->productSupplierFilterId = ctype_digit($value) && (int) $value > 0
            ? (string) (int) $value
            : '';
        $this->productSupplierState = '';
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function updatedProductSupplierState(): void
    {
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function showProductsWithoutSupplier(): void
    {
        abort_unless($this->group === 'product', 404);
        $this->productSupplierFilterId = '';
        $this->productSupplierState = 'unassigned';
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    public function updatedProductPerPage($value): void
    {
        $value = (int) $value;
        $this->productPerPage = in_array($value, [10, 20, 50, 100], true) ? $value : 10;
        $this->recordsReady = true;
        $this->resetPage('masterPage');
    }

    public function clearProductFilters(): void
    {
        $this->search = '';
        $this->productMainCategory = '';
        $this->productCategory = '';
        $this->productClientAvailability = '';
        $this->productStatus = '';
        $this->productSupplierFilterId = '';
        $this->productSupplierState = '';
        $this->recordsReady = true;
        $this->clearProductSelection();
        $this->resetPage('masterPage');
    }

    private function productFilterValues(): array
    {
        return [
            'main_category' => $this->productMainCategory,
            'parent_id' => $this->productCategory,
            'client_availability' => $this->productClientAvailability,
            'status' => $this->productStatus,
            'supplier_id' => $this->productSupplierFilterId,
            'supplier_state' => $this->productSupplierState,
        ];
    }

    private function filteredProductsQuery()
    {
        return app(MasterDataService::class)->query('product', $this->search, $this->productFilterValues());
    }
}
