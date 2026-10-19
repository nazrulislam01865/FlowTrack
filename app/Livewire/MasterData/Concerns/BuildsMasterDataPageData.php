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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait BuildsMasterDataPageData
{
    public function render()
    {
        $this->authorizeGroupAction('view');
        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $editorOnly = $this->showModal
            || $this->supplierCreateMode
            || ($this->group === 'supplier' && ($this->supplierViewId || $this->supplierEditId))
            || ($this->group === 'product_category' && $this->categoryEditorLevel);
        $summaries = $editorOnly
            ? collect()
            : MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', $this->group)
                ->selectRaw('type, count(*) as total_count')
                ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active_count")
                ->groupBy('type')
                ->get()
                ->keyBy('type');

        $rows = null;
        // Progressive rendering fallback retained for non-product groups: ? $service->paginate($this->group, $this->search, 30)
        if ($this->recordsReady && !($this->group === 'product' && $this->showProductView)) {
            if ($this->group === 'product') {
                $rows = $service->paginate($this->group, $this->search, $this->productPerPage, [
                    'main_category' => $this->productMainCategory,
                    'parent_id' => $this->productCategory,
                    'client_availability' => $this->productClientAvailability,
                    'status' => $this->productStatus,
                    'supplier_id' => $this->productSupplierFilterId,
                    'supplier_state' => $this->productSupplierState,
                ]);
            } elseif ($this->group === 'supplier') {
                $rows = $this->supplierListRows($workspaceId);
            } else {
                $rows = $service->paginate($this->group, $this->search, 30);
            }
        }

        $selected = $summaries->get($this->group);

        $parents = $this->showModal && $this->group === 'product' && ($this->editId || $this->productTaxonomyReady)
            ? $service->active('product_category')
            : ($this->showModal && $this->group === 'state' ? $service->active('country') : collect());

        // Create/Edit Product must consume the exact same canonical taxonomy as
        // the Product Categories page. Do not infer child categories from
        // products: newly-created Product Categories/Subcategories may have zero
        // products and still need to be immediately selectable.
        $productTaxonomyReady = $this->group === 'product'
            && (! $this->showModal || $this->editId || $this->productTaxonomyReady);
        $productTaxonomy = $productTaxonomyReady
            ? app(\App\Services\ProductTaxonomyService::class)
            : null;
        $canonicalMainCategories = $productTaxonomy ? $productTaxonomy->mainCategories(true) : collect();
        $canonicalProductCategories = $productTaxonomy ? $productTaxonomy->productCategories(true) : collect();

        $productFormCategories = $canonicalProductCategories;
        if ($this->group === 'product' && $this->showModal && trim($this->productFormMainCategory) !== '') {
            $mainNeedle = mb_strtolower(trim($this->productFormMainCategory));
            $selectedMain = $canonicalMainCategories
                ->first(fn (MasterRecord $main) => mb_strtolower(trim($main->name)) === $mainNeedle);

            $productFormCategories = $canonicalProductCategories
                ->filter(function (MasterRecord $category) use ($productTaxonomy, $selectedMain, $mainNeedle): bool {
                    $main = $productTaxonomy?->mainCategoryFor($category);
                    if ($selectedMain && $main) {
                        return (int) $main->id === (int) $selectedMain->id;
                    }

                    // Legacy fallback only; synchronizeLegacyTaxonomy() normally
                    // gives every Product Category a canonical main_category_id.
                    $legacyMain = trim((string) (
                        data_get($category->metadata, 'main_category')
                        ?: data_get($category->metadata, 'excel_main_category')
                    ));
                    return $legacyMain !== '' && mb_strtolower($legacyMain) === $mainNeedle;
                })
                ->values();

            if ($this->parentId && !$productFormCategories->contains('id', (int) $this->parentId)) {
                $selectedCategory = $canonicalProductCategories->firstWhere('id', (int) $this->parentId);
                if ($selectedCategory) $productFormCategories->push($selectedCategory);
            }
        }

        $categoryMatches = collect();
        $similarCategories = collect();
        $hasExactCategory = false;
        $productCodeDuplicate = null;

        if ($this->showModal && $this->group === 'product' && !$this->editId && $this->productTaxonomyReady) {
            $manualCode = trim($this->code);
            if ($manualCode !== '') {
                $productCodeDuplicate = MasterRecord::withTrashed()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->whereRaw('LOWER(code) = ?', [mb_strtolower($manualCode)])
                    ->first(['id', 'code', 'name', 'status', 'deleted_at']);
            }

            $needle = mb_strtolower(trim($this->productCategorySearch));
            if ($needle !== '') {
                $hasExactCategory = $parents->contains(fn (MasterRecord $category) => mb_strtolower($category->name) === $needle);
                $categoryMatches = $parents
                    ->filter(fn (MasterRecord $category) => str_contains(mb_strtolower($category->name), $needle))
                    ->take(6)
                    ->values();

                $matchedIds = $categoryMatches->pluck('id')->all();
                $similarCategories = $parents
                    ->reject(fn (MasterRecord $category) => in_array($category->id, $matchedIds, true))
                    ->sortBy(fn (MasterRecord $category) => levenshtein($needle, mb_strtolower($category->name)))
                    ->take(2)
                    ->values();
            }
        }

        // Keep two representations of main categories:
        // - records for native <select> filters on the Products list
        // - lightweight option arrays for the shared searchable selector used
        //   by Create/Edit Product and bulk actions. Mixing the two shapes caused
        //   Blade to try to escape an array as a string.
        $productMainCategoryFilterOptions = $this->group === 'product'
            ? $canonicalMainCategories
            : collect();
        $productMainCategories = $this->group === 'product'
            ? $canonicalMainCategories->map(fn (MasterRecord $main) => [
                'id' => $main->name,
                'label' => $main->name,
                'meta' => $main->code,
            ])->values()
            : collect();
        $productSubcategories = collect();
        if ($this->group === 'product' && $productTaxonomy) {
            $selectedCategoryId = (int) ($this->parentId ?? 0);

            $cataloguedSubcategories = $productTaxonomy->subcategories(true)
                ->when($selectedCategoryId > 0, fn ($items) => $items->where('parent_id', $selectedCategoryId))
                ->pluck('name');

            // Keep legacy product metadata visible during the transition, while
            // canonical Product Categories remains the source of truth.
            $legacySubcategories = $selectedCategoryId > 0
                ? MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->where('parent_id', $selectedCategoryId)
                    ->get(['metadata'])
                    ->map(fn (MasterRecord $product) => trim((string) (data_get($product->metadata, 'sub_category') ?: data_get($product->metadata, 'excel_sub_category'))))
                : collect();

            $productSubcategories = $cataloguedSubcategories
                ->concat($legacySubcategories)
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique(fn ($value) => mb_strtolower($value))
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        }

        $editProduct = null;
        if ($this->group === 'product' && $this->showModal && $this->editId) {
            $editProduct = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->with(['parent', 'creator'])
                ->find($this->editId);
        }

        $viewProduct = null;
        if ($this->group === 'product' && $this->showProductView && $this->viewProductId) {
            $viewProduct = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->with(['parent', 'creator'])
                ->when($this->productDetailSectionsReady['pricing'] ?? false,
                    fn ($query) => $query->with(['clientPrices.client:id,name,code']))
                ->find($this->viewProductId);
            if (! $viewProduct) {
                $this->showProductView = false;
                $this->viewProductId = null;
            }
        }

        $categoryMainPage = null;
        $categoryProductChildren = collect();
        $categorySubcategoryChildren = collect();
        $categoryMainCategories = collect();
        $categoryProductCategories = collect();
        $categorySubcategories = collect();
        $categoryParentOptions = collect();
        $categoryCounts = ['main' => 0, 'product' => 0, 'sub' => 0];
        $categoryProductCounts = collect();
        $categoryMainProductCounts = collect();
        $categorySubcategoryProductCounts = collect();
        $categoryProductChildTotals = collect();
        $categorySubcategoryChildTotals = collect();

        // A direct Add Category route opens the editor without hydrating the
        // category list behind it. Load only the parent choices that the compact
        // editor actually needs; the hierarchy/list counts stay deferred.
        if ($this->group === 'product_category' && $this->categoryEditorLevel && ! $this->recordsReady) {
            if ($this->categoryEditorLevel === 'product') {
                $categoryMainCategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_main_category')
                    ->active()
                    ->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'code', 'name', 'status', 'sort_order']);
            } elseif ($this->categoryEditorLevel === 'sub') {
                $categoryProductCategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_category')
                    ->active()
                    ->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'code', 'name', 'status', 'sort_order', 'metadata']);
            }
        }

        if ($this->group === 'product_category' && $this->recordsReady) {
            // Match the category lazy-pagination prototype exactly:
            // - only Main Categories are page-paginated;
            // - Product Category rows are queried only after their Main Category opens;
            // - Subcategory rows are queried only after their Product Category opens;
            // - child counts are lightweight count/id queries and do not hydrate child rows.
            $categoryMainCategories = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_main_category')
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'code', 'name', 'status', 'sort_order', 'updated_at']);

            $categoryCounts = [
                'main' => $categoryMainCategories->count(),
                'product' => (int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->count(),
                'sub' => (int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_subcategory')->count(),
            ];

            $mainIdByName = $categoryMainCategories
                ->mapWithKeys(fn (MasterRecord $main) => [mb_strtolower(trim((string) $main->name)) => (int) $main->id]);
            $mainNameById = $categoryMainCategories
                ->mapWithKeys(fn (MasterRecord $main) => [(int) $main->id => (string) $main->name]);

            $baseProductCategoryQuery = static fn () => MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_category');

            $applyProductMain = static function ($query, int $mainId, string $mainName) {
                return $query->where(function ($q) use ($mainId, $mainName) {
                    $q->where('metadata->main_category_id', $mainId)
                        ->orWhere('metadata->main_category', $mainName)
                        ->orWhere('metadata->excel_main_category', $mainName);
                });
            };

            $productMainId = static function (MasterRecord $category) use ($mainIdByName): int {
                $mainId = (int) data_get($category->metadata, 'main_category_id', 0);
                if ($mainId > 0) return $mainId;
                $legacyMain = mb_strtolower(trim((string) (
                    data_get($category->metadata, 'main_category')
                    ?: data_get($category->metadata, 'excel_main_category')
                )));
                return (int) ($mainIdByName[$legacyMain] ?? 0);
            };

            // The Parent filter is independent from table expansion. Load only
            // identifiers/names for Product Category options, never table rows.
            $productParentOptions = $baseProductCategoryQuery()
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name']);
            $categoryParentOptions = $categoryMainCategories->map(fn (MasterRecord $main) => [
                'value' => 'main:'.$main->id,
                'label' => $main->name,
                'meta' => 'Main category',
            ])->concat($productParentOptions->map(fn (MasterRecord $category) => [
                'value' => 'product:'.$category->id,
                'label' => $category->name,
                'meta' => 'Product category',
            ]))->values();

            // Only the Subcategory editor needs the complete Product Category
            // collection. The normal hierarchy list never preloads it.
            if ($this->categoryEditorLevel === 'sub') {
                $categoryProductCategories = $baseProductCategoryQuery()
                    ->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'code', 'name', 'status', 'sort_order', 'metadata']);
            }

            $searchNeedle = trim($this->search);
            $levelFilter = trim($this->categoryLevelFilter);
            $statusFilter = trim($this->categoryStatusFilter);
            [$parentFilterType, $parentFilterId] = str_contains($this->categoryParentFilter, ':')
                ? array_pad(explode(':', $this->categoryParentFilter, 2), 2, '')
                : ['', ''];
            $parentFilterId = (int) $parentFilterId;

            $directMainIds = collect();
            if (in_array($levelFilter, ['', 'main'], true)) {
                $directMainQuery = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_main_category')
                    ->when($searchNeedle !== '', fn ($query) => $query->where(function ($q) use ($searchNeedle) {
                        $q->whereLike('name', '%'.$searchNeedle.'%')
                            ->orWhereLike('code', '%'.$searchNeedle.'%');
                    }))
                    ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter));

                if ($parentFilterType === 'main' && $parentFilterId > 0) {
                    $directMainQuery->whereKey($parentFilterId);
                } elseif ($parentFilterType === 'product' && $parentFilterId > 0) {
                    $directMainQuery->whereRaw('1 = 0');
                }

                $directMainIds = $directMainQuery->pluck('id')->map(fn ($id) => (int) $id)->values();
            }

            $directProductIds = collect();
            $needDirectProducts = $levelFilter === 'product'
                || ($levelFilter === '' && ($searchNeedle !== '' || $statusFilter !== '' || $parentFilterType === 'product'));
            if ($needDirectProducts) {
                $directProductQuery = $baseProductCategoryQuery()
                    ->when($searchNeedle !== '', fn ($query) => $query->where(function ($q) use ($searchNeedle) {
                        $q->whereLike('name', '%'.$searchNeedle.'%')
                            ->orWhereLike('code', '%'.$searchNeedle.'%');
                    }))
                    ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter));

                if ($parentFilterType === 'main' && $parentFilterId > 0) {
                    $mainName = (string) ($mainNameById[$parentFilterId] ?? '');
                    $applyProductMain($directProductQuery, $parentFilterId, $mainName);
                } elseif ($parentFilterType === 'product' && $parentFilterId > 0) {
                    $directProductQuery->whereKey($parentFilterId);
                }

                $directProductIds = $directProductQuery->pluck('id')->map(fn ($id) => (int) $id)->values();
            }

            $matchingSubParentIds = collect();
            $needSubMatches = $levelFilter === 'sub'
                || ($levelFilter === '' && ($searchNeedle !== '' || $statusFilter !== ''));
            if ($needSubMatches) {
                $subMatchQuery = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_subcategory')
                    ->when($searchNeedle !== '', fn ($query) => $query->where(function ($q) use ($searchNeedle) {
                        $q->whereLike('name', '%'.$searchNeedle.'%')
                            ->orWhereLike('code', '%'.$searchNeedle.'%');
                    }))
                    ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter));

                if ($parentFilterType === 'product' && $parentFilterId > 0) {
                    $subMatchQuery->where('parent_id', $parentFilterId);
                } elseif ($parentFilterType === 'main' && $parentFilterId > 0) {
                    $mainName = (string) ($mainNameById[$parentFilterId] ?? '');
                    $productIdsForMain = $applyProductMain($baseProductCategoryQuery(), $parentFilterId, $mainName)->pluck('id');
                    $productIdsForMain->isNotEmpty()
                        ? $subMatchQuery->whereIn('parent_id', $productIdsForMain->all())
                        : $subMatchQuery->whereRaw('1 = 0');
                }

                $matchingSubParentIds = $subMatchQuery
                    ->pluck('parent_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            }

            // null means all Product Categories are eligible under a visible Main
            // Category. A Collection means restrict child queries to those IDs.
            $visibleProductIds = match ($levelFilter) {
                'main' => collect(),
                'product' => $directProductIds,
                'sub' => $matchingSubParentIds,
                default => ($searchNeedle === '' && $statusFilter === '' && $parentFilterType !== 'product')
                    ? null
                    : $directProductIds->concat($matchingSubParentIds)->unique()->values(),
            };
            if ($levelFilter === '' && $parentFilterType === 'product' && $parentFilterId > 0 && $searchNeedle === '' && $statusFilter === '') {
                $visibleProductIds = collect([$parentFilterId]);
            }

            $mainIdsFromProducts = collect();
            if ($visibleProductIds instanceof \Illuminate\Support\Collection && $visibleProductIds->isNotEmpty()) {
                $mainIdsFromProducts = $baseProductCategoryQuery()
                    ->whereIn('id', $visibleProductIds->all())
                    ->get(['id', 'metadata'])
                    ->map(fn (MasterRecord $category) => $productMainId($category))
                    ->filter()->unique()->values();
            }

            $noFilters = $levelFilter === '' && $searchNeedle === '' && $statusFilter === '' && $parentFilterType === '';
            $visibleMainIds = $noFilters
                ? null
                : match ($levelFilter) {
                    'main' => $directMainIds,
                    'product', 'sub' => $mainIdsFromProducts,
                    default => $directMainIds->concat($mainIdsFromProducts)->unique()->values(),
                };

            $mainPageQuery = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_main_category')
                ->orderBy('sort_order')->orderBy('name');
            if ($visibleMainIds instanceof \Illuminate\Support\Collection) {
                $visibleMainIds->isNotEmpty()
                    ? $mainPageQuery->whereIn('id', $visibleMainIds->all())
                    : $mainPageQuery->whereRaw('1 = 0');
            }

            $categoryMainPage = $mainPageQuery->paginate(
                max(1, $this->categoryPerPage),
                ['id', 'code', 'name', 'status', 'sort_order', 'updated_at'],
                'masterPage'
            );

            $pageMainIds = collect($categoryMainPage->items())->pluck('id')->map(fn ($id) => (int) $id)->values();

            // Main Category product totals are available while collapsed without
            // hydrating Product Category rows. Only child IDs for the six/selected
            // Main Categories on the current page are used for aggregation.
            foreach ($categoryMainPage->items() as $main) {
                $mainId = (int) $main->id;
                $mainName = (string) $main->name;

                $allCategoryIdsForMain = $applyProductMain($baseProductCategoryQuery(), $mainId, $mainName)
                    ->pluck('id')->map(fn ($id) => (int) $id)->values();
                $mainProductCount = 0;
                if ($allCategoryIdsForMain->isNotEmpty()) {
                    $mainProductCount += (int) MasterRecord::query()
                        ->forWorkspace($workspaceId)
                        ->ofType('product')
                        ->whereIn('parent_id', $allCategoryIdsForMain->all())
                        ->count();
                }
                $mainProductCount += (int) MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->whereNull('parent_id')
                    ->where(function ($query) use ($mainName) {
                        $query->where('metadata->main_category', $mainName)
                            ->orWhere('metadata->excel_main_category', $mainName);
                    })
                    ->count();
                $categoryMainProductCounts[mb_strtolower($mainName)] = $mainProductCount;

                if ($levelFilter === 'main') {
                    $categoryProductChildTotals[$mainId] = 0;
                    continue;
                }

                $childCountQuery = $applyProductMain($baseProductCategoryQuery(), $mainId, $mainName);
                if ($visibleProductIds instanceof \Illuminate\Support\Collection) {
                    $visibleProductIds->isNotEmpty()
                        ? $childCountQuery->whereIn('id', $visibleProductIds->all())
                        : $childCountQuery->whereRaw('1 = 0');
                }
                $categoryProductChildTotals[$mainId] = (int) $childCountQuery->count();
            }

            // Product Category rows are the first true lazy level: exactly four
            // are fetched on expand and four more on each Load more click.
            $loadedProductIds = collect();
            $expandedMainIds = collect($this->expandedMainCategoryIds)->map(fn ($id) => (int) $id);
            foreach ($categoryMainPage->items() as $main) {
                $mainId = (int) $main->id;
                if (! $expandedMainIds->contains($mainId) || $levelFilter === 'main') continue;

                $mainName = (string) $main->name;
                $limit = max(self::CATEGORY_PRODUCT_BATCH, (int) ($this->categoryProductLimits[$mainId] ?? self::CATEGORY_PRODUCT_BATCH));
                $childQuery = $applyProductMain($baseProductCategoryQuery(), $mainId, $mainName);
                if ($visibleProductIds instanceof \Illuminate\Support\Collection) {
                    $visibleProductIds->isNotEmpty()
                        ? $childQuery->whereIn('id', $visibleProductIds->all())
                        : $childQuery->whereRaw('1 = 0');
                }

                $rowsForMain = $childQuery
                    ->orderBy('sort_order')->orderBy('name')
                    ->limit($limit)
                    ->get(['id', 'code', 'name', 'status', 'sort_order', 'metadata', 'updated_at']);
                $categoryProductChildren[$mainId] = $rowsForMain;
                $loadedProductIds = $loadedProductIds->concat($rowsForMain->pluck('id')->map(fn ($id) => (int) $id));
            }
            $loadedProductIds = $loadedProductIds->unique()->values();

            if ($loadedProductIds->isNotEmpty()) {
                $categoryProductCounts = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->whereIn('parent_id', $loadedProductIds->all())
                    ->selectRaw('parent_id, COUNT(*) as aggregate')
                    ->groupBy('parent_id')
                    ->pluck('aggregate', 'parent_id')
                    ->map(fn ($count) => (int) $count);
            }

            // Child totals for the loaded Product Category rows use grouped count
            // queries only. No Subcategory row is fetched until the row expands.
            if ($loadedProductIds->isNotEmpty() && in_array($levelFilter, ['', 'sub'], true)) {
                $subCountQuery = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_subcategory')
                    ->whereIn('parent_id', $loadedProductIds->all())
                    ->when($searchNeedle !== '', fn ($query) => $query->where(function ($q) use ($searchNeedle) {
                        $q->whereLike('name', '%'.$searchNeedle.'%')
                            ->orWhereLike('code', '%'.$searchNeedle.'%');
                    }))
                    ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter));
                if ($parentFilterType === 'product' && $parentFilterId > 0) {
                    $subCountQuery->where('parent_id', $parentFilterId);
                }
                $categorySubcategoryChildTotals = $subCountQuery
                    ->selectRaw('parent_id, COUNT(*) as aggregate')
                    ->groupBy('parent_id')
                    ->pluck('aggregate', 'parent_id')
                    ->map(fn ($count) => (int) $count);
            }

            // Subcategory rows are the second true lazy level: exactly three are
            // fetched on expand and three more on each Load more click.
            $expandedProductIds = collect($this->expandedProductCategoryIds)->map(fn ($id) => (int) $id);
            foreach ($loadedProductIds as $productCategoryId) {
                $productCategoryId = (int) $productCategoryId;
                if (! $expandedProductIds->contains($productCategoryId)) continue;
                if (! in_array($levelFilter, ['', 'sub'], true)) continue;
                if ($parentFilterType === 'product' && $parentFilterId > 0 && $productCategoryId !== $parentFilterId) continue;

                $limit = max(self::CATEGORY_SUBCATEGORY_BATCH, (int) ($this->categorySubcategoryLimits[$productCategoryId] ?? self::CATEGORY_SUBCATEGORY_BATCH));
                $subQuery = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_subcategory')
                    ->where('parent_id', $productCategoryId)
                    ->when($searchNeedle !== '', fn ($query) => $query->where(function ($q) use ($searchNeedle) {
                        $q->whereLike('name', '%'.$searchNeedle.'%')
                            ->orWhereLike('code', '%'.$searchNeedle.'%');
                    }))
                    ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter))
                    ->orderBy('sort_order')->orderBy('name');

                $categorySubcategoryChildren[$productCategoryId] = $subQuery
                    ->limit($limit)
                    ->get(['id', 'parent_id', 'code', 'name', 'status', 'sort_order', 'updated_at']);
            }

            // Product counts for visible Subcategory rows are calculated only
            // after their Product Category has been expanded.
            $expandedLoadedIds = $expandedProductIds->intersect($loadedProductIds)->values();
            if ($expandedLoadedIds->isNotEmpty()) {
                $visibleSubNamesByProduct = collect($categorySubcategoryChildren)->map(
                    fn ($subs) => collect($subs)->map(fn (MasterRecord $sub) => mb_strtolower(trim((string) $sub->name)))->flip()
                );
                MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->whereIn('parent_id', $expandedLoadedIds->all())
                    ->select(['parent_id', 'metadata'])
                    ->chunk(500, function ($products) use (&$categorySubcategoryProductCounts, $visibleSubNamesByProduct): void {
                        foreach ($products as $product) {
                            $parentId = (int) $product->parent_id;
                            $sub = mb_strtolower(trim((string) (
                                data_get($product->metadata, 'sub_category')
                                ?: data_get($product->metadata, 'excel_sub_category')
                            )));
                            if ($sub === '' || ! $visibleSubNamesByProduct->get($parentId, collect())->has($sub)) continue;
                            $key = $parentId.'|'.$sub;
                            $categorySubcategoryProductCounts[$key] = ((int) ($categorySubcategoryProductCounts[$key] ?? 0)) + 1;
                        }
                    });
            }

            // Only the open Subcategory editor needs the full Subcategory list.
            if ($this->categoryEditorLevel === 'sub') {
                $categorySubcategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_subcategory')
                    ->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'parent_id', 'code', 'name', 'status', 'sort_order']);
            }
        }
        $productSelectionCount = $this->group === 'product' ? $this->productSelectionCount() : 0;
        $supplierLookup = app(\App\Services\ProductSupplierLookup::class);
        $productSupplierFilterSelectedOptions = $this->group === 'product' && ! $this->showProductView && ! $this->showModal && $this->productSupplierFilterId !== ''
            ? app(\App\Services\FilterOptionService::class)->selectedOptions(
                auth()->user(), 'suppliers', 'product-list', [$this->productSupplierFilterId],
            )
            : collect();
        $productSuppliersByProduct = $this->group === 'product' && $rows
            ? $supplierLookup->forProducts(collect($rows->items()), $workspaceId)
            : collect();
        $viewProductSuppliers = $viewProduct
            ? $supplierLookup->forProducts(collect([$viewProduct]), $workspaceId)->get((int) $viewProduct->id, collect())
            : collect();

        // Supplier-approved overrides are authoritative in the Product Details view.
        // Read them in one query, not one query per tagged supplier.
        $viewProductSupplierPricingTables = collect();
        if ($viewProduct && ($this->productDetailSectionsReady['pricing'] ?? false)
            && app(\App\Services\Orders\SupplierCostVisibility::class)->allowed() && $viewProductSuppliers->isNotEmpty()) {
            $overrides = DB::table('supplier_product_prices')
                ->where('workspace_id', $workspaceId)->where('product_id', $viewProduct->id)
                ->whereIn('supplier_id', $viewProductSuppliers->pluck('id'))
                ->pluck('price_breakpoints', 'supplier_id');
            $pricing = app(\App\Services\SupplierBridge\SupplierPricing::class);
            $viewProductSupplierPricingTables = $viewProductSuppliers->map(function ($supplier) use ($overrides, $pricing, $viewProduct): array {
                $override = $overrides->get($supplier->id);
                return [
                    'name' => $supplier->name,
                    'rows' => $pricing->normalize($override !== null
                        ? (json_decode((string) $override, true) ?: [])
                        : $viewProduct->supplierPriceBreakpoints()),
                ];
            });
        }

        $bulkProductSupplierOptions = collect();
        $bulkProductSupplierProductCounts = collect();
        if ($this->group === 'product' && $this->bulkProductPanel === 'supplier') {
            $bulkProductSupplierOptions = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'metadata', 'status']);

            $optionIds = $bulkProductSupplierOptions->pluck('id')->map(fn ($id) => (int) $id)->flip();
            $bulkSupplierLinks = collect();
            MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->get(['id', 'metadata'])
                ->each(function (MasterRecord $product) use ($bulkSupplierLinks, $optionIds): void {
                    foreach ($product->productSupplierIds() as $supplierId) {
                        $supplierId = (int) $supplierId;
                        if ($optionIds->has($supplierId)) {
                            $bulkSupplierLinks->put((int) $product->id.':'.$supplierId, $supplierId);
                        }
                    }
                });

            if (Schema::hasTable('product_supplier_links')) {
                DB::table('product_supplier_links')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('supplier_id', $optionIds->keys()->all())
                    ->get(['product_id', 'supplier_id'])
                    ->each(function ($link) use ($bulkSupplierLinks): void {
                        $supplierId = (int) $link->supplier_id;
                        $bulkSupplierLinks->put((int) $link->product_id.':'.$supplierId, $supplierId);
                    });
            }

            $bulkProductSupplierProductCounts = $bulkSupplierLinks->values()->countBy();
        }
        $categorySelectionCount = $this->group === 'product_category' ? count($this->selectedCategoryKeys) : 0;
        $bulkProductCategories = collect();
        $bulkProductSubcategories = collect();
        if ($this->group === 'product' && $this->bulkProductPanel === 'category') {
            $bulkMainNeedle = mb_strtolower(trim($this->bulkProductMainCategory));
            $bulkCategoryIdsFromProducts = $bulkMainNeedle === '' ? [] : MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->get(['parent_id', 'metadata'])
                ->filter(fn (MasterRecord $product) => mb_strtolower($product->productMainCategory()) === $bulkMainNeedle)
                ->pluck('parent_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

            $bulkProductCategories = $service->active('product_category')
                ->filter(function (MasterRecord $category) use ($bulkMainNeedle, $bulkCategoryIdsFromProducts): bool {
                    if ($bulkMainNeedle === '') return true;
                    $main = trim((string) (data_get($category->metadata, 'main_category') ?: data_get($category->metadata, 'excel_main_category')));
                    return in_array((int) $category->id, $bulkCategoryIdsFromProducts, true)
                        || mb_strtolower($main) === $bulkMainNeedle
                        || mb_strtolower(trim((string) $category->name)) === $bulkMainNeedle;
                })->values();

            if ($this->bulkProductCategoryId) {
                $cataloguedBulkSubcategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product_subcategory')
                    ->active()
                    ->where('parent_id', $this->bulkProductCategoryId)
                    ->orderBy('sort_order')->orderBy('name')->pluck('name');
                $legacyBulkSubcategories = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->where('parent_id', $this->bulkProductCategoryId)
                    ->get(['metadata'])
                    ->map(fn (MasterRecord $product) => trim((string) (data_get($product->metadata, 'sub_category') ?: data_get($product->metadata, 'excel_sub_category'))));
                $bulkProductSubcategories = $cataloguedBulkSubcategories->concat($legacyBulkSubcategories)
                    ->map(fn ($value) => trim((string) $value))->filter()
                    ->unique(fn ($value) => mb_strtolower($value))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            }
        }

        $orderTaskFlagOptions = $this->group === 'order_task_status'
            ? $service->active('order_task_flag')
                ->reject(fn (MasterRecord $flag) => $flag->systemKey() === 'overdue')
                ->values()
            : collect();
        $orderFlagOptions = $this->group === 'order_task_flag'
            ? $service->active('order_flag')
            : collect();

        $availableProductShipmentUrgencies = collect();
        if ($this->group === 'product' && $this->showModal && ($this->editId || $this->productShipmentOptionsReady)) {
            $selectedShipmentUrgencyIds = collect($this->productShipmentUrgencies)
                ->pluck('shipment_urgency_id')
                ->map(fn ($value) => (int) $value)
                ->filter()
                ->unique();
            $availableProductShipmentUrgencies = $service->active('shipment_urgency');

            if ($selectedShipmentUrgencyIds->isNotEmpty()) {
                $selectedStoredUrgencies = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('shipment_urgency')
                    ->whereIn('id', $selectedShipmentUrgencyIds->all())
                    ->get();
                $availableProductShipmentUrgencies = $availableProductShipmentUrgencies
                    ->concat($selectedStoredUrgencies)
                    ->unique('id')
                    ->sortBy(fn (MasterRecord $urgency) => sprintf('%010d|%s', (int) $urgency->sort_order, mb_strtolower((string) $urgency->name)))
                    ->values();
            }
        }

        $supplierDetail = null;
        $supplierDetailProducts = collect();
        if ($this->group === 'supplier' && ! $this->supplierCreateMode && ($this->supplierEditId || $this->supplierViewId)) {
            $supplierDetailId = (int) ($this->supplierEditId ?: $this->supplierViewId);
            $supplierDetail = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->with('creator:id,name')
                ->findOrFail($supplierDetailId);
            $supplierDetailProducts = $this->supplierDetailProducts($workspaceId, $supplierDetail);
        }

        $supplierCreateCodeRows = $this->group === 'supplier' && ($this->supplierCreateMode || $this->supplierEditId)
            ? $this->supplierCreateCodeRows($workspaceId)
            : collect();
        $supplierCreateExamples = $this->group === 'supplier' && $this->supplierCreateMode
            ? $this->supplierCreateExamples($workspaceId)
            : collect();
        $supplierListSummary = $this->group === 'supplier'
            && ! $this->supplierCreateMode
            && ! $this->supplierViewId
            && ! $this->supplierEditId
            ? $this->supplierListSummary($workspaceId, $rows)
            : [
                'active_suppliers' => 0,
                'total_products' => 0,
                'assigned_products' => 0,
                'unassigned_products' => 0,
                'products_by_supplier' => collect(),
            ];

        return view('livewire.master-data.index', [
            'labels' => MasterDataService::LABELS,
            'rows' => $rows,
            'parents' => $parents,
            'categoryMatches' => $categoryMatches,
            'similarCategories' => $similarCategories,
            'hasExactCategory' => $hasExactCategory,
            'productCodeDuplicate' => $productCodeDuplicate,
            'productCategories' => $this->group === 'product' && (! $this->showModal || $this->editId || $this->productTaxonomyReady)
                ? $service->list('product_category')
                : collect(),
            'productFormCategories' => $productFormCategories,
            'productMainCategories' => $productMainCategories,
            'productMainCategoryFilterOptions' => $productMainCategoryFilterOptions,
            'productSubcategories' => $productSubcategories,
            'productClients' => $this->group === 'product' && $this->showModal && ($this->productClientAvailabilityMode === 'specific' || $this->productClientPrices !== []) && auth()->user()
                ? app(ProductClientOptions::class)->forEditor(auth()->user(), array_values(array_unique(array_merge(
                    $this->productClientIds,
                    array_map('intval', array_column($this->productClientPrices, 'client_id'))
                ))))
                : collect(),
            'availableProductShipmentUrgencies' => $availableProductShipmentUrgencies,
            'viewProduct' => $viewProduct,
            'viewProductSuppliers' => $viewProductSuppliers,
            'viewProductSupplierPricingTables' => $viewProductSupplierPricingTables,
            'editProduct' => $editProduct,
            'productSelectionCount' => $productSelectionCount,
            'productSuppliersByProduct' => $productSuppliersByProduct,
            'productSupplierFilterSelectedOptions' => $productSupplierFilterSelectedOptions,
            'bulkProductSupplierOptions' => $bulkProductSupplierOptions,
            'bulkProductSupplierProductCounts' => $bulkProductSupplierProductCounts,
            'categorySelectionCount' => $categorySelectionCount,
            'bulkProductCategories' => $bulkProductCategories,
            'bulkProductSubcategories' => $bulkProductSubcategories,
            'categoryMainPage' => $categoryMainPage,
            'categoryProductChildren' => $categoryProductChildren,
            'categorySubcategoryChildren' => $categorySubcategoryChildren,
            'categoryMainCategories' => $categoryMainCategories,
            'categoryProductCategories' => $categoryProductCategories,
            'categorySubcategories' => $categorySubcategories,
            'categoryParentOptions' => $categoryParentOptions,
            'categoryCounts' => $categoryCounts,
            'categoryProductCounts' => $categoryProductCounts,
            'categoryMainProductCounts' => $categoryMainProductCounts,
            'categorySubcategoryProductCounts' => $categorySubcategoryProductCounts,
            'categoryProductChildTotals' => $categoryProductChildTotals,
            'categorySubcategoryChildTotals' => $categorySubcategoryChildTotals,
            'groupCounts' => collect(MasterDataService::LABELS)->mapWithKeys(
                fn ($label, $type) => [$type => (int) ($summaries->get($type)?->total_count ?? 0)]
            ),
            'total' => (int) $summaries->sum('total_count'),
            'active' => (int) $summaries->sum('active_count'),
            'selectedTotal' => (int) ($selected?->total_count ?? 0),
            'selectedActive' => (int) ($selected?->active_count ?? 0),
            'orderTaskFlagOptions' => $orderTaskFlagOptions,
            'orderFlagOptions' => $orderFlagOptions,
            'supplierCreateCodeRows' => $supplierCreateCodeRows,
            'supplierCreateExamples' => $supplierCreateExamples,
            'supplierListSummary' => $supplierListSummary,
            'supplierDetail' => $supplierDetail,
            'supplierDetailProducts' => $supplierDetailProducts,
        ]);
    }
}
