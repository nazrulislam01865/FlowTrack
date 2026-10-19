<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProductTaxonomyService
{
    public function workspaceId(): int
    {
        return app(MasterDataService::class)->workspaceId();
    }

    /**
     * Normalize legacy/imported category data into the three canonical taxonomy
     * levels used by the Product form: main category -> product category -> subcategory.
     * Safe to call repeatedly.
     */
    public function synchronizeLegacyTaxonomy(): void
    {
        $workspaceId = $this->workspaceId();
        $categories = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_category')
            ->orderBy('id')
            ->get();

        $allProducts = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->get(['id', 'parent_id', 'metadata']);
        $productsByCategory = $allProducts->groupBy(fn (MasterRecord $product) => (int) ($product->parent_id ?? 0));

        $knownMainNames = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_main_category')
            ->pluck('name')
            ->concat($allProducts->map(fn (MasterRecord $product) => trim((string) (
                data_get($product->metadata, 'main_category')
                ?: data_get($product->metadata, 'excel_main_category')
            ))))
            ->concat($categories->map(fn (MasterRecord $category) => trim((string) (
                data_get($category->metadata, 'main_category')
                ?: data_get($category->metadata, 'excel_main_category')
            ))))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '' && mb_strtolower($value) !== 'uncategorized')
            ->unique(fn (string $value) => mb_strtolower($value))
            ->values();

        foreach ($categories as $category) {
            $metadata = (array) ($category->metadata ?? []);
            $mainName = trim((string) ($metadata['main_category'] ?? $metadata['excel_main_category'] ?? ''));

            if ($mainName === '') {
                $mainName = $productsByCategory->get((int) $category->id, collect())
                    ->map(fn (MasterRecord $product) => trim((string) (
                        data_get($product->metadata, 'main_category')
                        ?: data_get($product->metadata, 'excel_main_category')
                    )))
                    ->first(fn (string $value) => $value !== '') ?? '';
            }

            if ($mainName === '' && $knownMainNames->count() === 1) {
                $mainName = (string) $knownMainNames->first();
            }
            if ($mainName === '') {
                $mainName = 'Uncategorized';
            }

            $main = $this->findOrCreateMainCategory($mainName);
            $metadata['main_category'] = $main->name;
            $metadata['excel_main_category'] = $main->name;
            $metadata['main_category_id'] = $main->id;

            if ($category->metadata !== $metadata) {
                $category->metadata = $metadata;
                $category->saveQuietly();
            }

            foreach ($productsByCategory->get((int) $category->id, collect()) as $product) {
                $productMetadata = (array) ($product->metadata ?? []);
                $changed = false;

                if (trim((string) ($productMetadata['main_category'] ?? '')) !== $main->name) {
                    $productMetadata['main_category'] = $main->name;
                    $changed = true;
                }
                if (trim((string) ($productMetadata['excel_main_category'] ?? '')) === '') {
                    $productMetadata['excel_main_category'] = $main->name;
                    $changed = true;
                }

                $subName = trim((string) ($productMetadata['sub_category'] ?? $productMetadata['excel_sub_category'] ?? ''));
                if ($subName !== '') {
                    $this->findOrCreateSubcategory($category->id, $subName);
                }

                if ($changed) {
                    $product->metadata = $productMetadata;
                    $product->saveQuietly();
                }
            }
        }

        // Products can contain a main-category value even before a Product Category
        // has been assigned. Preserve those values in the canonical main-category list.
        MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->get(['metadata'])
            ->map(fn (MasterRecord $product) => trim((string) (
                data_get($product->metadata, 'main_category')
                ?: data_get($product->metadata, 'excel_main_category')
            )))
            ->filter()
            ->unique(fn (string $value) => mb_strtolower($value))
            ->each(fn (string $value) => $this->findOrCreateMainCategory($value));
    }

    public function mainCategories(bool $activeOnly = false): Collection
    {
        return MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_main_category')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function productCategories(bool $activeOnly = false): Collection
    {
        return MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_category')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function subcategories(bool $activeOnly = false): Collection
    {
        return MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_subcategory')
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function mainCategoryFor(MasterRecord $category): ?MasterRecord
    {
        if ($category->type !== 'product_category') return null;

        $mainId = (int) data_get($category->metadata, 'main_category_id', 0);
        if ($mainId > 0) {
            $main = MasterRecord::query()
                ->forWorkspace($this->workspaceId())
                ->ofType('product_main_category')
                ->find($mainId);
            if ($main) return $main;
        }

        $name = trim((string) (
            data_get($category->metadata, 'main_category')
            ?: data_get($category->metadata, 'excel_main_category')
        ));
        if ($name === '') return null;

        return MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_main_category')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function findOrCreateMainCategory(string $name): MasterRecord
    {
        $name = trim($name);
        $existing = MasterRecord::query()
            ->withTrashed()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_main_category')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            if ($existing->trashed()) $existing->restore();
            return $existing;
        }

        return $this->createTaxonomyRecord('product_main_category', 'MCAT', $name);
    }

    private function findOrCreateSubcategory(int $productCategoryId, string $name): MasterRecord
    {
        $name = trim($name);
        $existing = MasterRecord::query()
            ->withTrashed()
            ->forWorkspace($this->workspaceId())
            ->ofType('product_subcategory')
            ->where('parent_id', $productCategoryId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            if ($existing->trashed()) $existing->restore();
            return $existing;
        }

        return $this->createTaxonomyRecord('product_subcategory', 'SCAT', $name, $productCategoryId);
    }

    private function createTaxonomyRecord(string $type, string $prefix, string $name, ?int $parentId = null): MasterRecord
    {
        $workspaceId = $this->workspaceId();
        $highest = MasterRecord::query()
            ->withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType($type)
            ->where('code', 'like', $prefix.'-%')
            ->pluck('code')
            ->reduce(function (int $max, string $code) use ($prefix): int {
                return preg_match('/^'.preg_quote($prefix, '/').'-(\\d+)$/', $code, $matches)
                    ? max($max, (int) $matches[1])
                    : $max;
            }, 0);

        $record = new MasterRecord();
        $record->fill([
            'workspace_id' => $workspaceId,
            'parent_id' => $parentId,
            'type' => $type,
            'code' => $prefix.'-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'description' => null,
            'metadata' => null,
            'status' => 'active',
            'sort_order' => ((int) MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->max('sort_order')) + 1,
        ]);
        if (Schema::hasColumn('master_records', 'created_by')) $record->created_by = auth()->id();
        $record->save();

        return $record;
    }
}
