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

trait ManagesProductTaxonomyCreation
{
    private function resetCategoryCreatorState(): void
    {
        $this->categoryCreator = null;
        $this->newMainCategoryName = '';
        $this->newMainCategoryDescription = '';
        $this->newProductCategoryName = '';
        $this->newProductCategoryDescription = '';
        $this->newProductCategoryMain = '';
        $this->newSubcategoryName = '';
        $this->newSubcategoryDescription = '';
        $this->newSubcategoryProductCategoryId = null;
    }

    public function updatedProductCategorySearch(): void
    {
        if ($this->group !== 'product' || !$this->showModal || $this->editId) return;

        // Typing a new value means the previously selected category is no longer
        // authoritative. A product can only be created once a real category row
        // is selected (or created) from the catalogue picker.
        $this->parentId = null;
        $this->newProductCategoryName = trim($this->productCategorySearch);
        $this->resetValidation(['parentId', 'newProductCategoryName']);
    }

    public function updatedProductFormMainCategory(): void
    {
        if ($this->group === 'product' && $this->showModal) {
            $this->parentId = null;
            $this->productCategorySearch = '';
            $this->productSubcategory = '';
        }
        $this->resetValidation(['productFormMainCategory', 'parentId', 'productSubcategory']);
    }

    public function setProductTaxonomySelection(string $property, string $value): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        abort_unless(in_array($property, ['productFormMainCategory', 'parentId', 'productSubcategory'], true), 404);

        $taxonomy = app(\App\Services\ProductTaxonomyService::class);
        $value = trim($value);

        if ($property === 'productFormMainCategory') {
            $main = $taxonomy->mainCategories(true)
                ->first(fn (MasterRecord $record) => mb_strtolower(trim($record->name)) === mb_strtolower($value));

            if (!$main) {
                $this->addError('productFormMainCategory', 'The selected main category is not available.');
                return;
            }

            $this->productFormMainCategory = $main->name;
            $this->parentId = null;
            $this->productCategorySearch = '';
            $this->productSubcategory = '';
            $this->resetValidation(['productFormMainCategory', 'parentId', 'productSubcategory']);
            return;
        }

        if ($property === 'parentId') {
            if ($value === '') {
                $this->parentId = null;
                $this->productCategorySearch = '';
                $this->productSubcategory = '';
                $this->resetValidation(['parentId', 'productSubcategory']);
                return;
            }

            $category = $taxonomy->productCategories(true)->firstWhere('id', (int) $value);
            if (!$category) {
                $this->addError('parentId', 'The selected product category is not available.');
                return;
            }

            $main = $taxonomy->mainCategoryFor($category);
            if (!$main) {
                $this->addError('parentId', 'This product category is not linked to a main category.');
                return;
            }

            if ($this->productFormMainCategory !== ''
                && mb_strtolower(trim($this->productFormMainCategory)) !== mb_strtolower(trim($main->name))) {
                $this->addError('parentId', 'The selected product category does not belong to the selected main category.');
                return;
            }

            $this->productFormMainCategory = $main->name;
            $this->parentId = (int) $category->id;
            $this->productCategorySearch = $category->name;
            $this->productSubcategory = '';
            $this->resetValidation(['productFormMainCategory', 'parentId', 'productSubcategory']);
            return;
        }

        if ($value === '') {
            $this->productSubcategory = '';
            $this->resetValidation('productSubcategory');
            return;
        }

        $categoryId = (int) ($this->parentId ?? 0);
        $subcategory = $taxonomy->subcategories(true)
            ->first(fn (MasterRecord $record) => (int) $record->parent_id === $categoryId
                && mb_strtolower(trim($record->name)) === mb_strtolower($value));

        if (!$subcategory) {
            $this->addError('productSubcategory', 'The selected subcategory is not available under this product category.');
            return;
        }

        $this->productSubcategory = $subcategory->name;
        $this->resetValidation('productSubcategory');
    }

    public function updatedParentId($value): void
    {
        if ($this->group !== 'product' || !$this->showModal) return;

        $this->productSubcategory = '';
        $this->resetValidation(['parentId', 'productSubcategory']);
        $categoryId = (int) $value;
        if ($categoryId <= 0) return;

        $taxonomy = app(\App\Services\ProductTaxonomyService::class);
        $category = $taxonomy->productCategories(true)->firstWhere('id', $categoryId);
        if (!$category) return;

        $this->productCategorySearch = $category->name;
        $categoryMain = $taxonomy->mainCategoryFor($category);
        if ($categoryMain) $this->productFormMainCategory = $categoryMain->name;
    }

    public function updatedProductReferenceCode(): void
    {
        $this->productReferenceCode = trim($this->productReferenceCode);
        $this->resetValidation('productReferenceCode');
    }

    public function updatedProductClientAvailabilityMode(): void
    {
        if ($this->productClientAvailabilityMode === 'all') {
            $this->productClientIds = [];
        }
        $this->resetValidation('productClientIds');
        $this->syncProductClientPriceRowsForSelectedClients();
    }

    private function productCodeReadyForCategory(): bool
    {
        return true;
    }

    public function selectProductCategory(int $id): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        if (!$this->productCodeReadyForCategory()) return;

        $category = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product_category')
            ->active()
            ->findOrFail($id);

        $this->parentId = $category->id;
        $this->productCategorySearch = $category->name;
        $categoryMain = trim((string) (data_get($category->metadata, 'main_category') ?: data_get($category->metadata, 'excel_main_category')));
        if ($categoryMain !== '') $this->productFormMainCategory = $categoryMain;
        $this->productSubcategory = '';
        $this->newProductCategoryName = '';
        $this->resetValidation(['parentId', 'newProductCategoryName', 'productSubcategory']);
        $this->dispatch('product-category-selected');
    }

    public function openCategoryCreator(string $level): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        abort_unless(auth()->user()?->canModule('product_categories', 'create'), 403);
        abort_unless(in_array($level, ['main', 'product', 'sub'], true), 404);

        $this->resetValidation([
            'newMainCategoryName', 'newMainCategoryDescription',
            'newProductCategoryMain', 'newProductCategoryName', 'newProductCategoryDescription',
            'newSubcategoryProductCategoryId', 'newSubcategoryName', 'newSubcategoryDescription',
        ]);
        $this->categoryCreator = $level;

        if ($level === 'product') {
            $this->newProductCategoryMain = trim($this->productFormMainCategory);
        }
        if ($level === 'sub') {
            $this->newSubcategoryProductCategoryId = $this->parentId;
        }
    }

    public function closeCategoryCreator(): void
    {
        $this->categoryCreator = null;
        $this->resetValidation([
            'newMainCategoryName', 'newMainCategoryDescription',
            'newProductCategoryMain', 'newProductCategoryName', 'newProductCategoryDescription',
            'newSubcategoryProductCategoryId', 'newSubcategoryName', 'newSubcategoryDescription',
        ]);
    }

    public function createMainCategory(): void
    {
        $this->assertCanCreateProductTaxonomy('main');
        $this->newMainCategoryName = trim($this->newMainCategoryName);
        $data = $this->validate([
            'newMainCategoryName' => ['required', 'string', 'max:255'],
            'newMainCategoryDescription' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'newMainCategoryName' => 'main category name',
            'newMainCategoryDescription' => 'description',
        ]);

        $name = trim($data['newMainCategoryName']);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $duplicateRecord = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_main_category')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        $legacyDuplicate = app(\App\Services\ProductCatalogService::class)->mainCategories()
            ->contains(fn ($value) => mb_strtolower(trim((string) $value)) === mb_strtolower($name));

        if ($duplicateRecord || $legacyDuplicate) {
            $this->addError('newMainCategoryName', 'This main category name already exists. Choose a different name.');
            return;
        }

        $this->createTaxonomyRecord('product_main_category', 'MCAT', $name, $data['newMainCategoryDescription']);
        $this->productFormMainCategory = $name;
        $this->parentId = null;
        $this->productCategorySearch = '';
        $this->productSubcategory = '';
        $this->newMainCategoryName = '';
        $this->newMainCategoryDescription = '';
        $this->categoryCreator = null;
        $this->resetValidation(['productFormMainCategory', 'parentId']);
    }

    public function createProductCategory(): void
    {
        $this->assertCanCreateProductTaxonomy('product');
        $this->newProductCategoryMain = trim($this->newProductCategoryMain);
        $this->newProductCategoryName = trim($this->newProductCategoryName);
        $data = $this->validate([
            'newProductCategoryMain' => ['required', 'string', 'max:255'],
            'newProductCategoryName' => ['required', 'string', 'max:255'],
            'newProductCategoryDescription' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'newProductCategoryMain' => 'main category',
            'newProductCategoryName' => 'product category name',
            'newProductCategoryDescription' => 'description',
        ]);

        $main = trim($data['newProductCategoryMain']);
        $name = trim($data['newProductCategoryName']);
        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $existing = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_category')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            $this->addError('newProductCategoryName', $existing->status === 'active'
                ? 'This product category name already exists. Choose a different name.'
                : 'This product category already exists but is inactive. Activate it from Product Categories first.');
            return;
        }

        $mainRecordId = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_main_category')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($main)])
            ->value('id');

        $metadata = ['main_category' => $main, 'excel_main_category' => $main];
        if ($mainRecordId) $metadata['main_category_id'] = (int) $mainRecordId;

        $category = $service->save('product_category', [
            'code' => $service->nextCode('product_category'),
            'name' => $name,
            'description' => $data['newProductCategoryDescription'],
            'status' => 'active',
            'sort_order' => (int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->max('sort_order') + 1,
            'metadata' => $metadata,
        ]);

        $this->productFormMainCategory = $main;
        $this->parentId = $category->id;
        $this->productCategorySearch = $category->name;
        $this->productSubcategory = '';
        $this->newProductCategoryName = '';
        $this->newProductCategoryDescription = '';
        $this->newProductCategoryMain = '';
        $this->categoryCreator = null;
        $this->resetValidation(['productFormMainCategory', 'parentId']);
        $this->dispatch('product-category-created');
    }

    public function createProductSubcategory(): void
    {
        $this->assertCanCreateProductTaxonomy('sub');
        $this->newSubcategoryName = trim($this->newSubcategoryName);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $data = $this->validate([
            'newSubcategoryProductCategoryId' => [
                'required', 'integer',
                Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product_category')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'newSubcategoryName' => ['required', 'string', 'max:255'],
            'newSubcategoryDescription' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'newSubcategoryProductCategoryId' => 'product category',
            'newSubcategoryName' => 'subcategory name',
            'newSubcategoryDescription' => 'description',
        ]);

        $categoryId = (int) $data['newSubcategoryProductCategoryId'];
        $name = trim($data['newSubcategoryName']);
        $duplicateRecord = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_subcategory')
            ->where('parent_id', $categoryId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        $legacyDuplicate = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->where('parent_id', $categoryId)
            ->get(['metadata'])
            ->contains(fn (MasterRecord $product) => mb_strtolower(trim((string) (data_get($product->metadata, 'sub_category') ?: data_get($product->metadata, 'excel_sub_category')))) === mb_strtolower($name));

        if ($duplicateRecord || $legacyDuplicate) {
            $this->addError('newSubcategoryName', 'This subcategory name already exists under the selected product category.');
            return;
        }

        $record = $this->createTaxonomyRecord(
            'product_subcategory',
            'SCAT',
            $name,
            $data['newSubcategoryDescription'],
            $categoryId,
        );

        $this->parentId = $categoryId;
        $this->productCategorySearch = (string) $record->parent?->name;
        $categoryMain = trim((string) (data_get($record->parent?->metadata, 'main_category') ?: data_get($record->parent?->metadata, 'excel_main_category')));
        if ($categoryMain !== '') $this->productFormMainCategory = $categoryMain;
        $this->productSubcategory = $record->name;
        $this->newSubcategoryName = '';
        $this->newSubcategoryDescription = '';
        $this->newSubcategoryProductCategoryId = null;
        $this->categoryCreator = null;
        $this->resetValidation(['parentId', 'productSubcategory']);
    }

    private function assertCanCreateProductTaxonomy(string $level): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        abort_unless(in_array($level, ['main', 'product', 'sub'], true), 404);
        abort_unless(auth()->user()?->canModule('product_categories', 'create'), 403);
    }

    private function createTaxonomyRecord(string $type, string $prefix, string $name, ?string $description = null, ?int $parentId = null): MasterRecord
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $highest = MasterRecord::withTrashed()
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
            'name' => trim($name),
            'description' => blank($description) ? null : trim((string) $description),
            'metadata' => null,
            'status' => 'active',
            'sort_order' => (int) MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->max('sort_order') + 1,
        ]);
        if (Schema::hasColumn('master_records', 'created_by')) $record->created_by = auth()->id();
        $record->save();

        return $record->load('parent');
    }
}
