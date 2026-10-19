<?php

namespace App\Services;

use App\Models\MasterRecord;
use App\Models\MasterValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductCategoryDeletionService
{
    /**
     * Build the complete destructive impact for one or more taxonomy selection keys.
     * Keys are main:{id}, product:{id}, or sub:{id}.
     *
     * @return array{
     *   selected_count:int,
     *   selected_labels:array<int,string>,
     *   main_category_ids:array<int,int>,
     *   product_category_ids:array<int,int>,
     *   subcategory_ids:array<int,int>,
     *   product_ids:array<int,int>,
     *   main_categories:int,
     *   product_categories:int,
     *   subcategories:int,
     *   total_categories:int,
     *   products:int
     * }
     */
    public function preview(int $workspaceId, array $keys): array
    {
        $parsed = collect($keys)
            ->map(function ($key): ?array {
                if (! preg_match('/^(main|product|sub):(\d+)$/', trim((string) $key), $matches)) {
                    return null;
                }

                return ['level' => $matches[1], 'id' => (int) $matches[2]];
            })
            ->filter()
            ->unique(fn (array $item) => $item['level'].':'.$item['id'])
            ->values();

        if ($parsed->isEmpty()) {
            return $this->emptyPlan();
        }

        $selectedMainIds = $parsed->where('level', 'main')->pluck('id')->values();
        $selectedProductIds = $parsed->where('level', 'product')->pluck('id')->values();
        $selectedSubIds = $parsed->where('level', 'sub')->pluck('id')->values();

        $mainCategories = $selectedMainIds->isEmpty()
            ? collect()
            : MasterRecord::withTrashed()
                ->forWorkspace($workspaceId)
                ->ofType('product_main_category')
                ->whereIn('id', $selectedMainIds->all())
                ->get(['id', 'name']);

        $mainIds = $mainCategories->pluck('id')->map(fn ($id) => (int) $id)->values();
        $mainNames = $mainCategories->pluck('name')
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values();

        // Product categories are linked to a main category through metadata rather
        // than parent_id, so resolve both canonical IDs and legacy main-category names.
        $allProductCategories = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('product_category')
            ->get(['id', 'name', 'metadata']);

        $productCategories = $allProductCategories->filter(function (MasterRecord $category) use ($selectedProductIds, $mainIds, $mainNames): bool {
            if ($selectedProductIds->contains((int) $category->id)) {
                return true;
            }

            $metadata = (array) ($category->metadata ?? []);
            $mainId = (int) ($metadata['main_category_id'] ?? 0);
            $mainName = mb_strtolower(trim((string) ($metadata['main_category'] ?? $metadata['excel_main_category'] ?? '')));

            return ($mainId > 0 && $mainIds->contains($mainId))
                || ($mainName !== '' && $mainNames->contains($mainName));
        })->values();

        $productCategoryIds = $productCategories->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
        $productCategoryNames = $productCategories->pluck('name')
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values();

        $allSubcategories = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('product_subcategory')
            ->get(['id', 'parent_id', 'name']);

        $subcategories = $allSubcategories->filter(function (MasterRecord $subcategory) use ($selectedSubIds, $productCategoryIds): bool {
            return $selectedSubIds->contains((int) $subcategory->id)
                || $productCategoryIds->contains((int) $subcategory->parent_id);
        })->values();

        $subcategoryIds = $subcategories->pluck('id')->map(fn ($id) => (int) $id)->unique()->values();
        $subcategoryNamesByParent = $subcategories
            ->groupBy(fn (MasterRecord $record) => (int) $record->parent_id)
            ->map(fn (Collection $records) => $records->pluck('name')
                ->map(fn ($name) => mb_strtolower(trim((string) $name)))
                ->filter()
                ->unique()
                ->values());

        // Products are intentionally unassigned rather than deleted. Include legacy
        // metadata-only assignments too, otherwise taxonomy synchronization could
        // recreate a category that the user just permanently deleted.
        $products = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->get(['id', 'parent_id', 'name', 'metadata'])
            ->filter(function (MasterRecord $product) use ($productCategoryIds, $productCategoryNames, $mainNames, $subcategoryNamesByParent): bool {
                $parentId = (int) ($product->parent_id ?? 0);
                $metadata = (array) ($product->metadata ?? []);

                if ($parentId > 0 && $productCategoryIds->contains($parentId)) {
                    return true;
                }

                $mainName = mb_strtolower(trim((string) ($metadata['main_category'] ?? $metadata['excel_main_category'] ?? '')));
                if ($mainName !== '' && $mainNames->contains($mainName)) {
                    return true;
                }

                $categoryName = mb_strtolower(trim((string) ($metadata['category'] ?? $metadata['excel_category'] ?? '')));
                if ($categoryName !== '' && $productCategoryNames->contains($categoryName)) {
                    return true;
                }

                $subNames = $subcategoryNamesByParent->get($parentId, collect());
                if ($subNames->isEmpty()) {
                    return false;
                }

                $subName = mb_strtolower(trim((string) ($metadata['sub_category'] ?? $metadata['excel_sub_category'] ?? '')));

                return $subName !== '' && $subNames->contains($subName);
            })
            ->values();

        $selectedLabels = $parsed->map(function (array $selection) use ($mainCategories, $allProductCategories, $allSubcategories): ?string {
            $record = match ($selection['level']) {
                'main' => $mainCategories->firstWhere('id', $selection['id']),
                'product' => $allProductCategories->firstWhere('id', $selection['id']),
                'sub' => $allSubcategories->firstWhere('id', $selection['id']),
            };
            if (! $record) {
                return null;
            }

            $label = match ($selection['level']) {
                'main' => 'Main category',
                'product' => 'Product category',
                'sub' => 'Subcategory',
            };

            return $label.': '.$record->name;
        })->filter()->values();

        return [
            'selected_count' => $selectedLabels->count(),
            'selected_labels' => $selectedLabels->take(6)->all(),
            'selected_labels_more' => max(0, $selectedLabels->count() - 6),
            'main_category_ids' => $mainIds->all(),
            'product_category_ids' => $productCategoryIds->all(),
            'subcategory_ids' => $subcategoryIds->all(),
            'product_ids' => $products->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'main_categories' => $mainIds->count(),
            'product_categories' => $productCategoryIds->count(),
            'subcategories' => $subcategoryIds->count(),
            'total_categories' => $mainIds->count() + $productCategoryIds->count() + $subcategoryIds->count(),
            'products' => $products->count(),
        ];
    }

    /**
     * Permanently remove categories in child-first order and unassign all affected products.
     * The impact is recalculated inside the transaction so confirmation cannot execute a stale plan.
     */
    public function hardDelete(int $workspaceId, array $keys): array
    {
        $result = DB::transaction(function () use ($workspaceId, $keys): array {
            $plan = $this->preview($workspaceId, $keys);
            if (($plan['total_categories'] ?? 0) === 0) {
                return $plan;
            }

            $productIds = $plan['product_ids'];
            if ($productIds !== []) {
                MasterRecord::withTrashed()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->get()
                    ->each(function (MasterRecord $product): void {
                        $metadata = $this->withoutTaxonomyMetadata((array) ($product->metadata ?? []));
                        $metadata['taxonomy_unassigned'] = true;

                        $product->parent_id = null;
                        $product->metadata = $metadata === [] ? null : $metadata;
                        $product->saveQuietly();

                        // Keep the legacy mirror unassigned as well. Otherwise a later
                        // syncLegacy() could restore the old Product -> Category link.
                        if (Schema::hasTable('master_values')) {
                            $legacy = MasterValue::query()
                                ->where('group_key', 'products')
                                ->where('code', $product->code)
                                ->first();
                            if ($legacy) {
                                $legacy->parent_id = null;
                                $legacyMeta = $this->withoutTaxonomyMetadata((array) ($legacy->meta ?? []));
                                $legacyMeta['taxonomy_unassigned'] = true;
                                $legacy->meta = $legacyMeta === [] ? null : $legacyMeta;
                                $legacy->save();
                            }
                        }
                    });
            }

            $this->forceDeleteIds($workspaceId, 'product_subcategory', $plan['subcategory_ids']);
            $this->forceDeleteIds($workspaceId, 'product_category', $plan['product_category_ids']);
            $this->forceDeleteIds($workspaceId, 'product_main_category', $plan['main_category_ids']);

            return $plan;
        });

        // MasterDataService caches active rows independently of Eloquent model events.
        // Purge every taxonomy/product cache so the hard delete is visible immediately.
        foreach (['product', 'product_category', 'product_main_category', 'product_subcategory'] as $type) {
            Cache::forget("flowtrack:master:active:{$workspaceId}:{$type}");
        }
        Cache::forget("flowtrack:master:legacy-sync:{$workspaceId}");

        return $result;
    }

    private function forceDeleteIds(int $workspaceId, string $type, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $records = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType($type)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if (Schema::hasTable('master_values') && $records->isNotEmpty()) {
            $legacyGroup = match ($type) {
                'product_category' => 'product_categories',
                'product_main_category' => 'product_main_categories',
                'product_subcategory' => 'product_subcategories',
                default => null,
            };

            if ($legacyGroup) {
                MasterValue::query()
                    ->where('group_key', $legacyGroup)
                    ->whereIn('code', $records->pluck('code')->filter()->all())
                    ->delete();
            }
        }

        $records->each(fn (MasterRecord $record) => $record->forceDelete());
    }

    private function withoutTaxonomyMetadata(array $metadata): array
    {
        foreach ([
            'main_category', 'excel_main_category', 'main_category_id',
            'category', 'excel_category', 'category_id', 'product_category_id',
            'sub_category', 'excel_sub_category', 'subcategory_id', 'sub_category_id',
        ] as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }

    private function emptyPlan(): array
    {
        return [
            'selected_count' => 0,
            'selected_labels' => [],
            'selected_labels_more' => 0,
            'main_category_ids' => [],
            'product_category_ids' => [],
            'subcategory_ids' => [],
            'product_ids' => [],
            'main_categories' => 0,
            'product_categories' => 0,
            'subcategories' => 0,
            'total_categories' => 0,
            'products' => 0,
        ];
    }
}
