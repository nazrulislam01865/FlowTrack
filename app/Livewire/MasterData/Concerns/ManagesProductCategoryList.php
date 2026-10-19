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

trait ManagesProductCategoryList
{
    public function updatedCategoryLevelFilter(): void
    {
        $this->recordsReady = true;
        $this->clearCategorySelection();
        $this->resetCategoryLazyLoading();
        $this->resetPage('masterPage');
    }

    public function updatedCategoryParentFilter(): void
    {
        $this->recordsReady = true;
        $this->clearCategorySelection();
        $this->resetCategoryLazyLoading();
        $this->resetPage('masterPage');
    }

    public function updatedCategoryStatusFilter(): void
    {
        $this->recordsReady = true;
        $this->clearCategorySelection();
        $this->resetCategoryLazyLoading();
        $this->resetPage('masterPage');
    }

    public function updatedCategoryPerPage($value): void
    {
        $value = (int) $value;
        $this->categoryPerPage = in_array($value, [6, 10, 20, 50], true) ? $value : 6;
        $this->recordsReady = true;
        $this->resetCategoryLazyLoading();
        $this->resetPage('masterPage');
    }

    public function clearCategoryFilters(): void
    {
        $this->search = '';
        $this->categoryLevelFilter = '';
        $this->categoryParentFilter = '';
        $this->categoryStatusFilter = '';
        $this->recordsReady = true;
        $this->clearCategorySelection();
        $this->resetCategoryLazyLoading();
        $this->resetPage('masterPage');
    }

    private function resetCategoryLazyLoading(bool $collapse = true): void
    {
        $this->categoryProductLimits = [];
        $this->categorySubcategoryLimits = [];
        if ($collapse) {
            $this->expandedMainCategoryIds = [];
            $this->expandedProductCategoryIds = [];
        }
    }

    public function toggleCategoryExpansion(string $level, int $id): void
    {
        abort_unless($this->group === 'product_category', 404);
        $property = $level === 'main' ? 'expandedMainCategoryIds' : ($level === 'product' ? 'expandedProductCategoryIds' : null);
        abort_unless($property, 404);

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $expectedType = $level === 'main' ? 'product_main_category' : 'product_category';
        MasterRecord::query()->forWorkspace($workspaceId)->ofType($expectedType)->findOrFail($id);

        $values = collect($this->{$property})->map(fn ($value) => (int) $value);
        $opening = ! $values->contains($id);
        $this->{$property} = $opening
            ? $values->push($id)->unique()->values()->all()
            : $values->reject(fn ($value) => $value === $id)->values()->all();

        if ($opening && $level === 'main') {
            $this->categoryProductLimits[$id] = max(self::CATEGORY_PRODUCT_BATCH, (int) ($this->categoryProductLimits[$id] ?? 0));
        }
        if ($opening && $level === 'product') {
            $this->categorySubcategoryLimits[$id] = max(self::CATEGORY_SUBCATEGORY_BATCH, (int) ($this->categorySubcategoryLimits[$id] ?? 0));
        }
    }

    public function loadMoreCategoryProducts(int $mainCategoryId): void
    {
        abort_unless($this->group === 'product_category', 404);
        MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product_main_category')
            ->findOrFail($mainCategoryId);

        $this->categoryProductLimits[$mainCategoryId] = max(
            self::CATEGORY_PRODUCT_BATCH,
            (int) ($this->categoryProductLimits[$mainCategoryId] ?? self::CATEGORY_PRODUCT_BATCH) + self::CATEGORY_PRODUCT_BATCH
        );
    }

    public function loadMoreCategorySubcategories(int $productCategoryId): void
    {
        abort_unless($this->group === 'product_category', 404);
        MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product_category')
            ->findOrFail($productCategoryId);

        $this->categorySubcategoryLimits[$productCategoryId] = max(
            self::CATEGORY_SUBCATEGORY_BATCH,
            (int) ($this->categorySubcategoryLimits[$productCategoryId] ?? self::CATEGORY_SUBCATEGORY_BATCH) + self::CATEGORY_SUBCATEGORY_BATCH
        );
    }

    public function expandAllCategories(): void
    {
        abort_unless($this->group === 'product_category', 404);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $this->expandedMainCategoryIds = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_main_category')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->expandedProductCategoryIds = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($this->expandedMainCategoryIds as $id) {
            $this->categoryProductLimits[(int) $id] = self::CATEGORY_PRODUCT_BATCH;
        }
        foreach ($this->expandedProductCategoryIds as $id) {
            $this->categorySubcategoryLimits[(int) $id] = self::CATEGORY_SUBCATEGORY_BATCH;
        }
    }

    public function collapseAllCategories(): void
    {
        abort_unless($this->group === 'product_category', 404);
        $this->resetCategoryLazyLoading();
    }

    private function categoryTypeForLevel(string $level): string
    {
        return match ($level) {
            'main' => 'product_main_category',
            'product' => 'product_category',
            'sub' => 'product_subcategory',
            default => abort(404),
        };
    }
}
