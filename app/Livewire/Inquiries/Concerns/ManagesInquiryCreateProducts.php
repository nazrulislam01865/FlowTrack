<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use Throwable;

trait ManagesInquiryCreateProducts
{
    private function canUseCreateInquiryProducts(User $user): bool
    {
        // Create-Inquiry catalogue access is owned by the Products module.
        // The legacy Inquiry / Order Product Lines permission must not gate the
        // Product selector or the Create Product action on this screen.
        return $user->canModule('inquiries', 'create')
            && $user->canModule('catalog_products', 'view');
    }

    private function authorizeCreateInquiryProducts(): void
    {
        abort_unless($this->showCreate && $this->canUseCreateInquiryProducts(auth()->user()), 403);
    }

    /**
     * Keep Create Inquiry pricing synchronized with the Product Master quantity
     * price table. The price is derived from the selected Product and quantity;
     * it is not a manual per-Inquiry override on this screen.
     */
    /** Refresh all visible product prices when the selected client changes. */
    private function refreshCreateInquiryClientPrices(): void
    {
        if (!$this->showCreate || $this->createProductRows === []) return;
        $clientId = (int) ($this->clientId ?: 0);
        $productIds = collect($this->createProductRows)->pluck('product_id')->map(fn ($id) => (int) $id)
            ->filter()->unique()->values();
        if ($productIds->isEmpty()) return;
        $products = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')->active()
            ->whereIn('id', $productIds)
            ->with(['clientPrices' => fn ($query) => $query->where('client_id', $clientId)])
            ->get(['id', 'type', 'metadata'])->keyBy('id');
        foreach ($this->createProductRows as $index => $row) {
            $product = $products->get((int) ($row['product_id'] ?? 0));
            $quantity = (int) ($row['quantity'] ?? 0);
            $base = $product?->productPriceForQuantity($quantity, $clientId ?: null);
            $this->createProductRows[$index]['unit_price'] = $base === null ? '' : number_format($base, 2, '.', '');
            $this->resetValidation("createProductRows.$index.unit_price");
        }
    }

    public function updatedCreateProductRows(mixed $value, string $key): void
    {
        if (!$this->showCreate || !str_ends_with($key, '.quantity')) return;

        $index = (int) str($key)->before('.')->toString();
        if (!array_key_exists($index, $this->createProductRows)) return;

        $this->syncCreateInquiryProductBasePrice($index);
    }

    public function addCreateProductRow(): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_if(count($this->createProductRows) >= 25, 422, 'An Inquiry can contain up to 25 product rows.');
        $this->createProductRows[] = ['row_key' => (string) \Illuminate\Support\Str::uuid(), 'category' => '', 'product' => '', 'quantity' => 1, 'unit' => 'units', 'unit_price' => ''];
        $this->createProductRfqRows[] = $this->newCreateProductRfqState();
    }

    public function removeCreateProductRow(int $index): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(array_key_exists($index, $this->createProductRows), 422);
        unset($this->createProductRows[$index], $this->createProductRfqRows[$index]);
        $this->createProductRows = array_values($this->createProductRows);
        $this->createProductRfqRows = array_values($this->createProductRfqRows);
        $this->syncLegacyCreateRfqState();
        $this->resetValidation(['createProductRows', 'createProductRfqRows']);
    }

    public function duplicateCreateProductRow(int $index): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(array_key_exists($index, $this->createProductRows), 422);
        abort_if(count($this->createProductRows) >= 25, 422, 'An Inquiry can contain up to 25 products.');

        $row = $this->createProductRows[$index];
        $row['row_key'] = (string) \Illuminate\Support\Str::uuid();
        $this->createProductRows[] = $row;
        $this->createProductRfqRows[] = $this->normaliseCreateProductRfqState(
            $this->createProductRfqRows[$index] ?? []
        );
        $this->syncLegacyCreateRfqState();
        $this->resetValidation(['createProductRows', 'createProductRfqRows']);
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
        $this->authorizeCreateInquiryProducts();
        $this->createProductShowAllResults = true;
    }

    public function focusCreateProductSearch(): void
    {
        $this->authorizeCreateInquiryProducts();
        $this->dispatch('focus-create-order-product-search');
    }

    public function selectCreateProduct(int $productId): void
    {
        $this->authorizeCreateInquiryProducts();

        $product = app(\App\Services\ProductCatalogService::class)->findActiveProductOrFail($productId);

        $alreadySelected = collect($this->createProductRows)->contains(
            fn (array $row): bool => (int) ($row['product_id'] ?? 0) === (int) $product->id
        );

        if (!$alreadySelected) {
            abort_if(count($this->createProductRows) >= 25, 422, 'An Inquiry can contain up to 25 products.');

            if (!app(\App\Services\ProductCatalogService::class)->supplierForProduct($product)) {
                $this->openMissingProductSupplierModalFor($product, null, 'create_inquiry', true, 'Inquiry');
                $this->dispatch('create-order-product-selected');
                return;
            }

            $this->appendCreateInquiryProduct($product);
        }

        // Keep the product search permanently available for the next product.
        // Clear only its query after a selection so the selector returns to a
        // neutral state without hiding or muting the control.
        $this->createProductSearch = '';
        $this->createProductShowAllResults = false;
        $this->resetValidation('createProductRows');
        $this->dispatch('create-order-product-selected');
    }

    private function appendCreateInquiryProduct(MasterRecord $product): void
    {
        $productCategory = trim((string) ($product->parent?->name ?? ''));
        if ($productCategory === '') {
            $legacyDescription = trim((string) $product->description);
            $productCategory = trim(explode(' ·', $legacyDescription, 2)[0]);
        }
        $productCategory = $productCategory !== '' ? $productCategory : 'Uncategorized';

        $defaultQuantity = 1000;
        $basePrice = $product->productPriceForQuantity($defaultQuantity, $this->clientId);
        $this->createProductRows[] = [
            'row_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => (int) $product->id,
            'category' => $productCategory,
            'product' => (string) $product->name,
            'quantity' => $defaultQuantity,
            'unit' => 'units',
            'unit_price' => $basePrice !== null ? number_format($basePrice, 2, '.', '') : '',
            'notes' => '',
        ];

        // RFQ state remains product-scoped. If supplier resolution created or
        // linked a Product-Master supplier, it is seeded here automatically.
        $this->createProductRfqRows[] = $this->newCreateProductRfqState($product->fresh());
        $this->syncLegacyCreateRfqState();
    }

    private function syncCreateInquiryProductBasePrice(int $index): void
    {
        if (!array_key_exists($index, $this->createProductRows)) return;

        $productId = (int) ($this->createProductRows[$index]['product_id'] ?? 0);
        $quantity = (int) ($this->createProductRows[$index]['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            $this->createProductRows[$index]['unit_price'] = '';
            $this->resetValidation("createProductRows.$index.unit_price");
            return;
        }

        $product = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->active()
            ->find($productId);

        $basePrice = $product?->productPriceForQuantity($quantity, $this->clientId);
        $this->createProductRows[$index]['unit_price'] = $basePrice !== null
            ? number_format($basePrice, 2, '.', '')
            : '';

        $this->resetValidation("createProductRows.$index.unit_price");
    }

    public function incrementCreateProductQuantity(int $index): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(array_key_exists($index, $this->createProductRows), 422);
        $current = max(1, (int) ($this->createProductRows[$index]['quantity'] ?? 1));
        $this->createProductRows[$index]['quantity'] = min(999999999, $current + 1);
        $this->syncCreateInquiryProductBasePrice($index);
        $this->resetValidation("createProductRows.$index.quantity");
    }

    public function decrementCreateProductQuantity(int $index): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(array_key_exists($index, $this->createProductRows), 422);
        $current = max(1, (int) ($this->createProductRows[$index]['quantity'] ?? 1));
        $this->createProductRows[$index]['quantity'] = max(1, $current - 1);
        $this->syncCreateInquiryProductBasePrice($index);
        $this->resetValidation("createProductRows.$index.quantity");
    }

    public function openCreateOrderProductModal(): void
    {
        $this->authorizeCreateInquiryProducts();
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_if(count($this->createProductRows) >= 25, 422, 'An Inquiry can contain up to 25 products.');

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
        $this->authorizeCreateInquiryProducts();
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
        $this->authorizeCreateInquiryProducts();
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
        $this->authorizeCreateInquiryProducts();
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

        $category = app(\App\Actions\Inquiries\CreateInquiryProductCategory::class)->handle($name, auth()->user());

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

        if ($code === '') {
            $this->addError('newProductCode', 'Enter the SKU / product code first.');
            return false;
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
        $this->authorizeCreateInquiryProducts();
        abort_unless($this->showCreateOrderProductModal, 422);
        abort_unless(auth()->user()->canModule('catalog_products', 'create'), 403);
        abort_unless(auth()->user()->canModule('product_categories', 'view'), 403);
        abort_if(count($this->createProductRows) >= 25, 422, 'An Inquiry can contain up to 25 products.');

        $this->newProductCode = strtoupper(trim($this->newProductCode));
        $this->newProductName = trim($this->newProductName);

        $data = $this->validate([
            'newProductCode' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'newProductCategoryId' => ['required', 'integer'],
            'newProductName' => ['required', 'string', 'max:255'],
            'newProductImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $duplicate = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($data['newProductCode'])])
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
            $created = app(\App\Actions\Inquiries\CreateInquiryCatalogProduct::class)->handle(
                $data['newProductCode'],
                $data['newProductName'],
                $category,
                $this->newProductImage,
                auth()->user(),
            );
            $product = $created['product'];
            $imageStored = $created['image_stored'];
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?: 'The product could not be created.';
            $this->addError('newProductCode', $message);
            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('newProductCode', 'The product could not be created. Please try again.');
            return;
        }

        $this->selectCreateProduct((int) $product->id);
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
        $this->newProductImage = null;
        if ($resetSearchErrors) {
            $this->resetValidation([
                'newProductCode',
                'newProductCategoryId',
                'newProductCategoryName',
                'newProductName',
                'newProductImage',
            ]);
        }
    }
}
