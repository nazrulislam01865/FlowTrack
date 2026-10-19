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

trait ManagesProductCategoryEditor
{
    public function openCategoryEditor(string $level = 'main', ?int $id = null, ?int $parentId = null, bool $readOnly = false): void
    {
        abort_unless($this->group === 'product_category', 404);
        abort_unless(in_array($level, ['main', 'product', 'sub'], true), 404);
        $this->authorizeGroupAction($readOnly ? 'view' : ($id ? 'edit' : 'create'));

        // Opening a category editor must be a read-only navigation operation.
        // The legacy taxonomy synchronizer scans every product/category and may also
        // write normalization rows, so doing it here made the form wait for the
        // entire catalogue before it could render. Parent options are loaded by
        // BuildsMasterDataPageData with small, level-specific queries instead.
        $this->categoryEditorReadOnly = $readOnly;
        $this->categoryEditorLevel = $level;
        $this->categoryEditorId = $id;
        $this->categoryEditorParentId = $parentId;
        $this->categoryEditorName = '';
        $this->categoryEditorDescription = '';
        $this->categoryEditorStatus = 'active';
        $this->resetValidation([
            'categoryEditorLevel', 'categoryEditorParentId', 'categoryEditorName',
            'categoryEditorDescription', 'categoryEditorStatus',
        ]);

        if (!$id) return;

        $type = match ($level) {
            'main' => 'product_main_category',
            'product' => 'product_category',
            'sub' => 'product_subcategory',
        };
        $record = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType($type)
            ->findOrFail($id);

        $this->categoryEditorName = $record->name;
        $this->categoryEditorDescription = (string) $record->description;
        $this->categoryEditorStatus = $record->status;
        if ($level === 'product') {
            $this->categoryEditorParentId = app(\App\Services\ProductTaxonomyService::class)->mainCategoryFor($record)?->id;
        } elseif ($level === 'sub') {
            $this->categoryEditorParentId = $record->parent_id;
        }
    }

    public function viewCategory(string $level, int $id): void
    {
        $this->openCategoryEditor($level, $id, null, true);
    }

    public function updatedCategoryEditorLevel(): void
    {
        if ($this->categoryEditorId) return;
        $this->categoryEditorParentId = null;
        $this->resetValidation(['categoryEditorParentId', 'categoryEditorName']);
    }

    public function closeCategoryEditor(): void
    {
        $this->categoryEditorLevel = null;
        $this->categoryEditorId = null;
        $this->categoryEditorParentId = null;
        $this->categoryEditorName = '';
        $this->categoryEditorDescription = '';
        $this->categoryEditorStatus = 'active';
        $this->categoryEditorReadOnly = false;
        $this->resetValidation([
            'categoryEditorLevel', 'categoryEditorParentId', 'categoryEditorName',
            'categoryEditorDescription', 'categoryEditorStatus',
        ]);
    }

    public function saveCategoryEditor(): void
    {
        abort_unless($this->group === 'product_category', 404);
        abort_unless(!$this->categoryEditorReadOnly, 403);
        abort_unless(in_array($this->categoryEditorLevel, ['main', 'product', 'sub'], true), 404);
        $this->authorizeGroupAction($this->categoryEditorId ? 'edit' : 'create');

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $level = (string) $this->categoryEditorLevel;
        $type = match ($level) {
            'main' => 'product_main_category',
            'product' => 'product_category',
            'sub' => 'product_subcategory',
        };

        $rules = [
            'categoryEditorName' => ['required', 'string', 'max:255'],
            'categoryEditorDescription' => ['nullable', 'string', 'max:5000'],
            'categoryEditorStatus' => ['required', Rule::in(['active', 'inactive'])],
        ];
        if ($level !== 'main') {
            $parentType = $level === 'product' ? 'product_main_category' : 'product_category';
            $rules['categoryEditorParentId'] = [
                'required', 'integer',
                Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', $parentType)
                    ->whereNull('deleted_at')),
            ];
        }
        $data = $this->validate($rules, [], [
            'categoryEditorName' => 'name',
            'categoryEditorDescription' => 'description',
            'categoryEditorStatus' => 'status',
            'categoryEditorParentId' => $level === 'product' ? 'main category' : 'product category',
        ]);

        $name = trim($data['categoryEditorName']);
        $duplicate = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType($type)
            ->when($level === 'sub', fn ($q) => $q->where('parent_id', (int) $data['categoryEditorParentId']))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($this->categoryEditorId, fn ($q) => $q->where('id', '!=', $this->categoryEditorId))
            ->exists();
        if ($duplicate) {
            $this->addError('categoryEditorName', 'This category name already exists at this level.');
            return;
        }

        $record = $this->categoryEditorId
            ? MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->findOrFail($this->categoryEditorId)
            : new MasterRecord();
        $oldName = (string) $record->name;
        $oldParentId = (int) ($record->parent_id ?? 0);

        if (!$record->exists) {
            $prefix = match ($level) { 'main' => 'MCAT', 'product' => 'CAT', 'sub' => 'SCAT' };
            $highest = MasterRecord::withTrashed()->forWorkspace($workspaceId)->ofType($type)
                ->where('code', 'like', $prefix.'-%')->pluck('code')
                ->reduce(function (int $max, string $code) use ($prefix): int {
                    return preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $code, $matches) ? max($max, (int) $matches[1]) : $max;
                }, 0);
            $record->workspace_id = $workspaceId;
            $record->type = $type;
            $record->code = $prefix.'-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
            $record->sort_order = ((int) MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->max('sort_order')) + 1;
            if (Schema::hasColumn('master_records', 'created_by')) $record->created_by = auth()->id();
        }

        $record->name = $name;
        $record->description = blank($data['categoryEditorDescription']) ? null : trim((string) $data['categoryEditorDescription']);
        $record->status = $data['categoryEditorStatus'];

        if ($level === 'main') {
            $record->parent_id = null;
            $record->metadata = $record->metadata ?: null;
        } elseif ($level === 'product') {
            $main = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_main_category')->findOrFail((int) $data['categoryEditorParentId']);
            $metadata = (array) ($record->metadata ?? []);
            $metadata['main_category'] = $main->name;
            $metadata['excel_main_category'] = $main->name;
            $metadata['main_category_id'] = $main->id;
            $record->parent_id = null;
            $record->metadata = $metadata;
        } else {
            $record->parent_id = (int) $data['categoryEditorParentId'];
        }
        $record->save();

        if ($level === 'main' && $oldName !== '' && mb_strtolower($oldName) !== mb_strtolower($record->name)) {
            MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->get()->each(function (MasterRecord $category) use ($oldName, $record): void {
                $metadata = (array) ($category->metadata ?? []);
                $mainId = (int) ($metadata['main_category_id'] ?? 0);
                $mainName = trim((string) ($metadata['main_category'] ?? $metadata['excel_main_category'] ?? ''));
                if ($mainId !== (int) $record->id && mb_strtolower($mainName) !== mb_strtolower($oldName)) return;
                $metadata['main_category'] = $record->name;
                $metadata['excel_main_category'] = $record->name;
                $metadata['main_category_id'] = $record->id;
                $category->metadata = $metadata;
                $category->saveQuietly();
                MasterRecord::query()->forWorkspace($category->workspace_id)->ofType('product')->where('parent_id', $category->id)->get()->each(function (MasterRecord $product) use ($record): void {
                    $productMetadata = (array) ($product->metadata ?? []);
                    $productMetadata['main_category'] = $record->name;
                    $productMetadata['excel_main_category'] = $record->name;
                    $product->metadata = $productMetadata;
                    $product->saveQuietly();
                });
            });
        }

        if ($level === 'product') {
            $main = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_main_category')->find((int) $this->categoryEditorParentId);
            if ($main) {
                MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')->where('parent_id', $record->id)->get()->each(function (MasterRecord $product) use ($main): void {
                    $metadata = (array) ($product->metadata ?? []);
                    $metadata['main_category'] = $main->name;
                    $metadata['excel_main_category'] = $main->name;
                    $product->metadata = $metadata;
                    $product->saveQuietly();
                });
            }
        }

        if ($level === 'sub' && $this->categoryEditorId && $oldName !== '' && (mb_strtolower($oldName) !== mb_strtolower($record->name) || $oldParentId !== (int) $record->parent_id)) {
            MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')->where('parent_id', $oldParentId)->get()->each(function (MasterRecord $product) use ($oldName, $record): void {
                $metadata = (array) ($product->metadata ?? []);
                $current = trim((string) ($metadata['sub_category'] ?? $metadata['excel_sub_category'] ?? ''));
                if (mb_strtolower($current) !== mb_strtolower($oldName)) return;
                $metadata['sub_category'] = $record->name;
                $metadata['excel_sub_category'] = $record->name;
                $product->parent_id = $record->parent_id;
                $product->metadata = $metadata;
                $product->saveQuietly();
            });
        }

        if ($level === 'product' && $this->categoryEditorParentId) {
            $this->expandedMainCategoryIds = collect($this->expandedMainCategoryIds)->push((int) $this->categoryEditorParentId)->unique()->values()->all();
        } elseif ($level === 'sub' && $record->parent_id) {
            $this->expandedProductCategoryIds = collect($this->expandedProductCategoryIds)->push((int) $record->parent_id)->unique()->values()->all();
        }

        $wasEditing = (bool) $this->categoryEditorId;
        $this->recordsReady = true;
        $this->closeCategoryEditor();
        session()->flash('success', $wasEditing ? 'Category updated.' : 'Category created.');
    }

    public function toggleCategoryStatus(string $level, int $id): void
    {
        abort_unless($this->group === 'product_category', 404);
        $this->authorizeGroupAction('edit');
        $type = match ($level) { 'main' => 'product_main_category', 'product' => 'product_category', 'sub' => 'product_subcategory', default => abort(404) };
        $record = MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())->ofType($type)->findOrFail($id);
        $record->status = $record->status === 'active' ? 'inactive' : 'active';
        $record->save();
        $this->recordsReady = true;
        session()->flash('success', 'Category status updated.');
    }

    public function deleteCategory(string $level, int $id): void
    {
        $this->openCategoryDeleteConfirmation($level, $id);
    }
}
