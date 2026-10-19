<?php

namespace App\Livewire\Jobs\Concerns;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use App\Services\Orders\OrderProductPricing;
use App\Services\Orders\SupplierCostVisibility;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesCreateOrderProducts
{
    /** Refresh all visible product prices when the selected client changes. */
    private function refreshCreateOrderClientPrices(): void
    {
        if (!$this->showCreate || $this->jobItems === []) return;
        $clientId = (int) ($this->clientId ?: 0);
        $productIds = collect($this->jobItems)->pluck('product_id')->map(fn ($id) => (int) $id)
            ->filter()->unique()->values();
        if ($productIds->isEmpty()) return;
        $products = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')->active()
            ->whereIn('id', $productIds)
            ->with(['clientPrices' => fn ($query) => $query->where('client_id', $clientId)])
            ->get(['id', 'type', 'metadata'])->keyBy('id');
        foreach ($this->jobItems as $index => $row) {
            $product = $products->get((int) ($row['product_id'] ?? 0));
            $quantity = (int) ($row['quantity'] ?? 0);
            $base = $product?->productPriceForQuantity($quantity, $clientId ?: null);
            $this->jobItems[$index]['unit_price'] = $base === null ? '' : number_format($base, 2, '.', '');
            $this->resetValidation("jobItems.$index.unit_price");
        }
        $this->refreshCreateOrderSupplierPrices();
    }

    /** Never hydrate confidential costs for an unauthorized Livewire user. */
    private function refreshCreateOrderSupplierPrices(): void
    {
        $allowed = app(SupplierCostVisibility::class)->allowed();
        if (! $allowed) {
            foreach ($this->jobItems as $index => $row) unset($this->jobItems[$index]['supplier_price']);
            return;
        }
        $ids = collect($this->jobItems)->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique();
        if ($ids->isEmpty()) return;
        $products = MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')->active()->whereIn('id', $ids)->get(['id', 'type', 'metadata', 'workspace_id'])->keyBy('id');
        $costs = app(OrderProductPricing::class)->supplierUnitPrices($this->jobItems, $products);
        foreach ($this->jobItems as $index => $row) {
            $key = (int) ($row['product_id'] ?? 0).':'.(int) ($row['supplier_id'] ?? 0);
            $price = $costs[$key] ?? null;
            $this->jobItems[$index]['supplier_price'] = $price === null ? '' : number_format($price, 2, '.', '');
        }
    }

    public function updatedJobItems(mixed $value, string $key): void
    {
        if (!$this->showCreate) return;

        $index = (int) str($key)->before('.')->toString();
        if (!array_key_exists($index, $this->jobItems)) return;

        if (str_ends_with($key, '.quantity')) {
            $this->syncCreateOrderProductBasePrice($index);
            return;
        }

        if (!str_ends_with($key, '.category')) return;

        // A product belongs to the selected category. Clear any stale product
        // immediately so the remote selector cannot submit a value from the
        // previous category.
        $this->jobItems[$index]['product'] = '';
    }

    public function updatedCreateProductSearch(): void
    {
        $this->createProductShowAllResults = false;
    }

    public function updatedCreateProductCategoryFilter(): void
    {
        if (trim($this->createProductCategoryFilter) !== '') {
            abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        }
        $this->createProductShowAllResults = false;
        $this->dispatch('open-create-order-product-results');
    }

    public function showAllCreateProductResults(): void
    {
        $this->authorizeCreateOrderProducts();
        $this->createProductShowAllResults = true;
    }

    public function focusCreateProductSearch(): void
    {
        $this->authorizeCreateOrderProducts();
        $this->dispatch('focus-create-order-product-search');
    }

    public function selectCreateProduct(int $productId): void
    {
        $this->authorizeCreateOrderProducts();

        // Resolve from the canonical Product catalogue. Passing a Product
        // Category ID here now results in 404 instead of ever selecting it.
        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail($productId);

        $alreadySelected = collect($this->jobItems)->contains(
            fn (array $row): bool => (int) ($row['product_id'] ?? 0) === (int) $product->id
        );

        if (!$alreadySelected) {
            abort_if(count($this->jobItems) >= 25, 422, 'An Order can contain up to 25 products.');

            // Start from the Product Master default supplier. When one is
            // missing, the dedicated resolution modal can link an existing
            // supplier, create and link a new supplier, or explicitly skip.
            $productSupplier = app(\App\Services\ProductCatalogService::class)->supplierForProduct($product);
            if (!$productSupplier) {
                $this->openMissingProductSupplierModalFor($product);
                $this->dispatch('create-order-product-selected');
                return;
            }

            $this->createOrderSupplierSkipProductIds = array_values(array_filter(
                $this->createOrderSupplierSkipProductIds,
                fn (int $id): bool => $id !== (int) $product->id
            ));
            $this->appendCreateOrderProduct($product, (int) $productSupplier->id);
        }

        $this->createProductShowAllResults = false;
        $this->resetValidation('jobItems');
        $this->dispatch('create-order-product-selected');
    }

    public function updateCreateOrderProductSupplierFromSelector(string $property, mixed $supplierId): array
    {
        $this->authorizeCreateOrderProducts();
        abort_unless(preg_match('/^create-order-item-supplier:(\d+)$/', $property, $matches) === 1, 422, 'Invalid supplier target.');

        $index = (int) $matches[1];
        abort_unless(array_key_exists($index, $this->jobItems), 422, 'That product row is no longer available.');

        $supplierId = filled($supplierId) ? (int) $supplierId : 0;
        abort_unless($supplierId > 0, 422, 'Select a supplier.');

        $productId = (int) ($this->jobItems[$index]['product_id'] ?? 0);
        abort_unless($productId > 0, 422, 'That product row is no longer available.');

        $catalog = app(\App\Services\ProductCatalogService::class);
        $product = $catalog->findActiveProductOrFail($productId);
        $supplier = $catalog->allSuppliersForProducts(collect([$product]))
            ->get($productId, collect())
            ->first(fn (MasterRecord $linked): bool => (int) $linked->id === $supplierId);
        abort_unless($supplier, 422, 'Select a supplier linked to this product.');

        $this->jobItems[$index]['supplier_id'] = (int) $supplier->id;
        $this->createOrderSupplierOverrides[$productId] = (int) $supplier->id;
        $this->createOrderSupplierSkipProductIds = array_values(array_filter(
            $this->createOrderSupplierSkipProductIds,
            fn (int $id): bool => $id !== $productId
        ));
        $this->resetValidation("jobItems.$index.supplier_id");
        $this->syncCreateOrderProductBasePrice($index);
        $this->dispatch('create-order-product-supplier-selected');

        $label = $supplier->supplierShortCode() ?: (string) $supplier->name;

        return ['ok' => true, 'value' => (string) $supplier->id, 'label' => $label];
    }

    private function appendCreateOrderProduct(MasterRecord $product, ?int $supplierId): void
    {
        // The selected row is always a Product master record. Category is
        // metadata copied from its parent (or a safe legacy fallback), never
        // the value used as the selected product itself.
        $productCategory = trim((string) ($product->parent?->name ?? ''));
        if ($productCategory === '') {
            $legacyDescription = trim((string) $product->description);
            $productCategory = trim(explode(' ·', $legacyDescription, 2)[0]);
        }
        $productCategory = $productCategory !== '' ? $productCategory : 'Uncategorized';

        $defaultQuantity = 1000;
        $basePrice = $product->productPriceForQuantity($defaultQuantity, $this->clientId);

        $this->jobItems[] = [
            'product_id' => (int) $product->id,
            'category' => $productCategory,
            'product' => (string) $product->name,
            'quantity' => $defaultQuantity,
            'supplier_id' => $supplierId,
            'unit_price' => $basePrice !== null ? number_format($basePrice, 2, '.', '') : '',
            'remote_area_surcharge' => '',
            'notes' => '',
        ];
        $index = array_key_last($this->jobItems);
        $this->syncCreateOrderProductBasePrice($index);
    }

    /**
     * Keep the Create Order base/unit price synchronized with the Product
     * Master's customer quantity price table. Order creation never treats this value as
     * a manual override: changing quantity immediately resolves the matching
     * price breakpoint for the selected Product.
     */
    private function syncCreateOrderProductBasePrice(int $index): void
    {
        if (!array_key_exists($index, $this->jobItems)) return;

        $productId = (int) ($this->jobItems[$index]['product_id'] ?? 0);
        $quantity = (int) ($this->jobItems[$index]['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            $this->jobItems[$index]['unit_price'] = '';
            if (app(SupplierCostVisibility::class)->allowed()) $this->jobItems[$index]['supplier_price'] = '';
            $this->resetValidation("jobItems.$index.unit_price");
            return;
        }

        $product = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->active()
            ->find($productId);

        $basePrice = $product?->productPriceForQuantity($quantity, $this->clientId);
        $this->jobItems[$index]['unit_price'] = $basePrice !== null
            ? number_format($basePrice, 2, '.', '')
            : '';

        $this->resetValidation("jobItems.$index.unit_price");
        $this->refreshCreateOrderSupplierPrices();
    }

    public function applyCreateProductRemoteSurcharge(int $index): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless(array_key_exists($index, $this->jobItems), 422);
        $row = $this->jobItems[$index];
        $product = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')->active()->findOrFail((int) ($row['product_id'] ?? 0));
        $price = $product->productRemoteSurchargeForQuantity((int) ($row['quantity'] ?? 0));
        abort_if($price === null, 422, 'No remote area surcharge is configured for this product and quantity.');
        $this->jobItems[$index]['remote_area_surcharge'] = number_format($price, 2, '.', '');
        $this->resetValidation("jobItems.$index.remote_area_surcharge");
    }

    public function incrementCreateProductQuantity(int $index): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless(array_key_exists($index, $this->jobItems), 422);
        $current = max(1, (int) ($this->jobItems[$index]['quantity'] ?? 1));
        $this->jobItems[$index]['quantity'] = min(999999999, $current + 1);
        $this->syncCreateOrderProductBasePrice($index);
        $this->resetValidation("jobItems.$index.quantity");
    }

    public function decrementCreateProductQuantity(int $index): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless(array_key_exists($index, $this->jobItems), 422);
        $current = max(1, (int) ($this->jobItems[$index]['quantity'] ?? 1));
        $this->jobItems[$index]['quantity'] = max(1, $current - 1);
        $this->syncCreateOrderProductBasePrice($index);
        $this->resetValidation("jobItems.$index.quantity");
    }

    public function openCreateOrderProductModal(): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_if(count($this->jobItems) >= 25, 422, 'An Order can contain up to 25 products.');

        $this->resetCreateOrderProductModal();
        $this->showCreateOrderProductModal = true;
    }

    public function openCreateOrderProductModalFromSearch(): void
    {
        $searchedName = trim($this->createProductSearch);
        $this->openCreateOrderProductModal();
        $this->newProductName = $searchedName;
    }

    public function closeCreateOrderProductModal(): void
    {
        $this->showCreateOrderProductModal = false;
        $this->resetCreateOrderProductModal();
    }

    public function selectCreateOrderProductCategory(int $categoryId): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless($this->showCreateOrderProductModal, 422);
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        if (!$this->newProductCodeReadyForCategory()) return;

        $category = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product_category')
            ->active()
            ->findOrFail($categoryId);

        $this->newProductCategoryId = (int) $category->id;
        $this->newProductCategorySearch = (string) $category->name;
        $this->newProductCategoryName = '';
        $this->resetValidation(['newProductCategoryId', 'newProductCategoryName']);
        $this->dispatch('create-order-product-category-selected');
    }

    public function beginCreateOrderProductCategory(): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless($this->showCreateOrderProductModal, 422);
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'create'), 403);
        if (!$this->newProductCodeReadyForCategory()) return;
        $this->newProductCategoryName = trim($this->newProductCategorySearch);
        $this->resetValidation('newProductCategoryName');
    }

    public function cancelCreateOrderProductCategory(): void
    {
        $this->newProductCategoryName = '';
        $this->resetValidation('newProductCategoryName');
    }

    public function createCreateOrderProductCategory(): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless($this->showCreateOrderProductModal, 422);
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'create'), 403);
        if (!$this->newProductCodeReadyForCategory()) return;

        $name = trim($this->newProductCategoryName ?: $this->newProductCategorySearch);
        $this->newProductCategoryName = $name;
        $this->validate(['newProductCategoryName' => ['required', 'string', 'max:255']]);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $existing = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('product_category')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            if ($existing->trashed() || $existing->status !== 'active') {
                $this->addError('newProductCategoryName', 'A category with this name already exists but is inactive. Activate it from Product Categories first.');
                return;
            }

            $this->newProductCategoryId = (int) $existing->id;
            $this->newProductCategorySearch = (string) $existing->name;
            $this->newProductCategoryName = '';
            $this->resetValidation(['newProductCategoryId', 'newProductCategoryName']);
            $this->dispatch('create-order-product-category-created');
            return;
        }

        $category = $service->save('product_category', [
            'code' => $service->nextCode('product_category'),
            'name' => $name,
            'description' => null,
            'parent_id' => null,
            'status' => 'active',
            'sort_order' => ((int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->max('sort_order')) + 1,
            'metadata' => null,
        ]);

        $this->newProductCategoryId = (int) $category->id;
        $this->newProductCategorySearch = (string) $category->name;
        $this->newProductCategoryName = '';
        $this->resetValidation(['newProductCategoryId', 'newProductCategoryName']);
        $this->dispatch('create-order-product-category-created');
    }

    public function updatedNewProductCategorySearch(): void
    {
        if ($this->showCreateOrderProductModal) {
            abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        }
        $search = trim($this->newProductCategorySearch);
        if ($this->newProductCategoryId) {
            $selectedName = MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('product_category')
                ->whereKey($this->newProductCategoryId)
                ->value('name');

            if (!$selectedName || mb_strtolower(trim((string) $selectedName)) !== mb_strtolower($search)) {
                $this->newProductCategoryId = null;
            }
        }
        $this->resetValidation('newProductCategoryId');
    }

    public function updatedNewProductCode(): void
    {
        $this->newProductCode = strtoupper(trim($this->newProductCode));
        $this->resetValidation('newProductCode');
    }

    private function newProductCodeReadyForCategory(): bool
    {
        $code = strtoupper(trim($this->newProductCode));
        $this->newProductCode = $code;

        // SKU is optional in the Create Order quick-create flow. When it is
        // omitted, MasterDataService generates the canonical Product code at
        // save time. A manually entered SKU still has to be valid and unique.
        if ($code === '') {
            $this->resetValidation('newProductCode');
            return true;
        }

        if (mb_strlen($code) > 40 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $code) !== 1) {
            $this->addError('newProductCode', 'Use letters, numbers, dots, dashes or underscores only. Maximum 40 characters.');
            return false;
        }

        $duplicate = MasterRecord::withTrashed()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->first();

        if ($duplicate) {
            $this->addError('newProductCode', $duplicate->trashed()
                ? 'This product code is reserved by an archived product.'
                : 'This product code already exists.');
            return false;
        }

        $this->resetValidation('newProductCode');
        return true;
    }

    public function updatedNewProductImage(): void
    {
        abort_unless($this->showCreateOrderProductModal, 422);
        $this->validateOnly('newProductImage', [
            'newProductImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    public function selectDuplicateCreateOrderProduct(int $productId): void
    {
        $this->selectCreateProduct($productId);
        $this->showCreateOrderProductModal = false;
        $this->resetCreateOrderProductModal();
    }

    public function viewSimilarCreateProducts(): void
    {
        $this->createProductSearch = trim($this->newProductName);
        if ($this->newProductCategoryId) {
            $this->createProductCategoryFilter = (string) $this->newProductCategoryId;
        }
        $this->showCreateOrderProductModal = false;
        $this->resetCreateOrderProductModal(false);
        $this->dispatch('focus-create-order-product-search');
    }

    public function createAndAddOrderProduct(): void
    {
        $this->authorizeCreateOrderProducts();
        abort_unless($this->showCreateOrderProductModal, 422);
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        abort_if(count($this->jobItems) >= 25, 422, 'An Order can contain up to 25 products.');

        $this->newProductCode = strtoupper(trim($this->newProductCode));
        $this->newProductName = trim($this->newProductName);

        $data = $this->validate([
            'newProductCode' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'newProductCategoryId' => ['required', 'integer'],
            'newProductName' => ['required', 'string', 'max:255'],
            'newProductSupplierId' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'supplier')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'newProductImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $productCode = strtoupper(trim((string) ($data['newProductCode'] ?? '')));
        if ($productCode === '') {
            $productCode = $service->nextCode('product');
        }

        $duplicate = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($productCode)])
            ->first();

        if ($duplicate) {
            $this->addError('newProductCode', $duplicate->trashed()
                ? 'This product code is reserved by an archived product.'
                : 'This product code already exists.');
            return;
        }

        $category = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product_category')
            ->active()
            ->find((int) $data['newProductCategoryId']);
        if (!$category) {
            $this->addError('newProductCategoryId', 'Select a valid active product category.');
            return;
        }

        try {
            $product = $service->save('product', [
                'code' => $productCode,
                'name' => $data['newProductName'],
                'description' => null,
                'parent_id' => $category->id,
                'status' => 'active',
                'sort_order' => ((int) MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')->max('sort_order')) + 1,
                'metadata' => filled($data['newProductSupplierId'] ?? null)
                    ? ['supplier_id' => (int) $data['newProductSupplierId']]
                    : [],
            ]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?: 'The product could not be created.';
            $this->addError('newProductCode', $message);
            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('newProductCode', 'The product could not be created. Please try again.');
            return;
        }

        $imageStored = true;
        if ($this->newProductImage) {
            try {
                app(ProductImageService::class)->replace($product, $this->newProductImage);
            } catch (Throwable $exception) {
                report($exception);
                $imageStored = false;
            }
        }

        $supplierId = filled($data['newProductSupplierId'] ?? null) ? (int) $data['newProductSupplierId'] : null;
        if ($supplierId === null && !in_array((int) $product->id, $this->createOrderSupplierSkipProductIds, true)) {
            // The Product can intentionally have no default supplier. Mark this
            // Order row as an explicit supplier skip so quick-create does not
            // immediately open the missing-supplier confirmation modal.
            $this->createOrderSupplierSkipProductIds[] = (int) $product->id;
        }
        $this->appendCreateOrderProduct($product, $supplierId);
        $this->createProductShowAllResults = false;
        $this->resetValidation('jobItems');
        $this->dispatch('create-order-product-selected');
        $this->showCreateOrderProductModal = false;
        $this->resetCreateOrderProductModal();
        if (!$imageStored) {
            $this->dispatch('flowtrack-toast', message: 'Product created and selected. The image could not be stored.');
        }
    }

    private function resetCreateOrderProductModal(bool $resetSearchErrors = true): void
    {
        $this->newProductCode = '';
        $this->newProductCategoryId = null;
        $this->newProductCategorySearch = '';
        $this->newProductCategoryName = '';
        $this->newProductName = '';
        $this->newProductSupplierId = null;
        $this->newProductImage = null;
        if ($resetSearchErrors) {
            $this->resetValidation([
                'newProductCode',
                'newProductCategoryId',
                'newProductCategoryName',
                'newProductName',
                'newProductSupplierId',
                'newProductImage',
            ]);
        }
    }

}
