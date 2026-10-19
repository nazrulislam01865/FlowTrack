<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Services\AccessControlService;
use App\Services\MasterDataService;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

trait ManagesInquiryProducts
{
    public function openEditInquiryProduct(int $itemId): void
    {
        if ($this->showAddInquiryProductForm) {
            $this->closeAddInquiryProductForm();
        }
        $user = auth()->user();
        $inquiry = $this->selectedInquiry();

        abort_unless(
            $user->canModule('catalog_products', 'edit')
            && app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit($user, $inquiry)
            && ! $inquiry->result,
            403
        );

        $item = InquiryItem::query()
            ->where('inquiry_id', $inquiry->id)
            ->findOrFail($itemId);

        $catalog = app(\App\Services\ProductCatalogService::class);
        $product = null;
        if (filled($item->item_name)) {
            $productQuery = $catalog->activeProductsQuery()
                ->with(['parent' => fn ($parent) => $parent->where('type', 'product_category')])
                ->where('name', trim((string) $item->item_name));
            if (filled($item->category)) {
                $productQuery->whereHas('parent', fn ($parent) => $parent
                    ->where('type', 'product_category')
                    ->where('name', trim((string) $item->category)));
            }
            $product = $productQuery->first();
        }

        $quantity = max(1, (int) round((float) ($item->quantity ?? 1)));
        $basePrice = $product?->productPriceForQuantity($quantity, (int) $inquiry->client_id);

        $this->editInquiryProductItemId = (int) $item->id;
        $this->editInquiryProductSelectedId = $product ? (int) $product->id : null;
        $this->editInquiryProductSearch = '';
        $this->editInquiryProductShowAllResults = false;
        $this->editInquiryProductCategory = $product
            ? $this->inquiryEditProductCategory($product)
            : (string) ($item->category ?? '');
        $this->editInquiryProductName = (string) ($product?->name ?: $item->item_name ?: '');
        $this->editInquiryProductQuantity = (string) $quantity;
        $this->editInquiryProductUnitPrice = number_format(
            $basePrice !== null ? (float) $basePrice : (float) ($item->unit_price ?? 0),
            2,
            '.',
            ''
        );
        $this->editInquiryProductNotes = (string) ($item->notes ?? '');
        $this->resetValidation([
            'editInquiryProductSelectedId',
            'editInquiryProductQuantity',
            'editInquiryProductUnitPrice',
            'editInquiryProductNotes',
        ]);
    }

    public function closeEditInquiryProduct(): void
    {
        $this->editInquiryProductItemId = null;
        $this->editInquiryProductSelectedId = null;
        $this->editInquiryProductSearch = '';
        $this->editInquiryProductShowAllResults = false;
        $this->editInquiryProductCategory = '';
        $this->editInquiryProductName = '';
        $this->editInquiryProductQuantity = '1';
        $this->editInquiryProductUnitPrice = '0.00';
        $this->editInquiryProductNotes = '';
        $this->resetValidation([
            'editInquiryProductSelectedId',
            'editInquiryProductQuantity',
            'editInquiryProductUnitPrice',
            'editInquiryProductNotes',
        ]);
    }

    public function showAllEditInquiryProductResults(): void
    {
        abort_unless($this->editInquiryProductItemId, 422);
        $this->editInquiryProductShowAllResults = true;
    }

    public function updatedEditInquiryProductSearch(): void
    {
        if (!$this->editInquiryProductItemId) {
            return;
        }

        $this->editInquiryProductShowAllResults = false;

        // Product is the source of truth for the dependent detail fields. Once
        // the user searches for a replacement, hide stale category/price data
        // until a Product Master search result is explicitly selected.
        if (
            $this->editInquiryProductSelectedId
            && strcasecmp(trim($this->editInquiryProductSearch), trim($this->editInquiryProductName)) !== 0
        ) {
            $this->editInquiryProductSelectedId = null;
            $this->editInquiryProductCategory = '';
            $this->editInquiryProductName = '';
            $this->editInquiryProductUnitPrice = '0.00';
            $this->resetValidation([
                'editInquiryProductSelectedId',
                'editInquiryProductUnitPrice',
            ]);
        }
    }

    public function selectEditInquiryProduct(int $productId): void
    {
        abort_unless($this->editInquiryProductItemId && $this->selectedInquiryId, 422);

        $user = auth()->user();
        $inquiry = $this->selectedInquiry();
        abort_unless(
            $user->canModule('catalog_products', 'edit')
            && app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit($user, $inquiry)
            && ! $inquiry->result,
            403
        );

        $product = app(\App\Services\ProductCatalogService::class)->findActiveProductOrFail($productId);
        $quantity = max(1, (int) $this->editInquiryProductQuantity);
        $basePrice = $product->productPriceForQuantity($quantity, (int) $inquiry->client_id);

        $this->editInquiryProductSelectedId = (int) $product->id;
        $this->editInquiryProductSearch = (string) $product->name;
        $this->editInquiryProductShowAllResults = false;
        $this->editInquiryProductCategory = $this->inquiryEditProductCategory($product);
        $this->editInquiryProductName = (string) $product->name;
        $this->editInquiryProductUnitPrice = $basePrice !== null
            ? number_format((float) $basePrice, 2, '.', '')
            : '0.00';

        $this->resetValidation([
            'editInquiryProductSelectedId',
            'editInquiryProductQuantity',
            'editInquiryProductUnitPrice',
        ]);
        $this->dispatch('detail-product-edit-selected');
    }

    public function updatedEditInquiryProductQuantity(): void
    {
        if (!$this->editInquiryProductItemId || !$this->editInquiryProductSelectedId) {
            return;
        }

        $quantity = (int) $this->editInquiryProductQuantity;
        if ($quantity <= 0) {
            $this->editInquiryProductUnitPrice = '0.00';
            return;
        }

        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $this->editInquiryProductSelectedId);
        $basePrice = $product->productPriceForQuantity($quantity, (int) $this->selectedInquiry()->client_id);
        $this->editInquiryProductUnitPrice = $basePrice !== null
            ? number_format((float) $basePrice, 2, '.', '')
            : '0.00';
        $this->resetValidation('editInquiryProductUnitPrice');
    }

    public function saveEditInquiryProduct(): void
    {
        abort_unless($this->editInquiryProductItemId, 422);

        $user = auth()->user();
        $inquiry = $this->selectedInquiry();
        abort_unless(
            $user->canModule('catalog_products', 'edit')
            && app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit($user, $inquiry)
            && ! $inquiry->result,
            403
        );

        $data = $this->validate([
            'editInquiryProductSelectedId' => ['required', 'integer', 'min:1'],
            'editInquiryProductQuantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'editInquiryProductUnitPrice' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'editInquiryProductNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'editInquiryProductSelectedId.required' => 'Search for and select a product first.',
            'editInquiryProductQuantity.required' => 'Enter a quantity.',
        ]);

        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $data['editInquiryProductSelectedId']);
        $category = $this->inquiryEditProductCategory($product);
        if ($category === 'Uncategorized') {
            $this->addError('editInquiryProductSelectedId', 'This product does not have an active product category.');
            return;
        }

        $item = InquiryItem::query()
            ->where('inquiry_id', $inquiry->id)
            ->findOrFail($this->editInquiryProductItemId);

        $duplicate = $inquiry->items()
            ->where('id', '!=', $item->id)
            ->whereRaw('LOWER(item_name) = ?', [mb_strtolower((string) $product->name)])
            ->exists();
        if ($duplicate) {
            $this->addError('editInquiryProductSelectedId', 'This product is already added to the Inquiry.');
            return;
        }

        $action = app(\App\Actions\Inquiries\UpdateInquiryItem::class);
        $originalCategory = (string) ($item->category ?? '');
        $originalProduct = (string) ($item->item_name ?? '');

        if ($category !== $originalCategory) {
            $action->handle($inquiry, $item, 'category', $category, $user);
            $item = $item->refresh();
        }
        if ($category !== $originalCategory || $product->name !== $originalProduct) {
            $action->handle($inquiry, $item, 'item_name', (string) $product->name, $user);
            $item = $item->refresh();
        }

        $quantity = (int) $data['editInquiryProductQuantity'];
        if ($quantity !== (int) round((float) ($item->quantity ?? 0))) {
            $action->handle($inquiry, $item, 'quantity', $quantity, $user);
            $item = $item->refresh();
        }

        $basePrice = $product->productPriceForQuantity($quantity, (int) $inquiry->client_id);
        $unitPrice = round((float) ($basePrice ?? $data['editInquiryProductUnitPrice']), 2);
        $currentUnitPrice = $item->unit_price !== null ? round((float) $item->unit_price, 2) : null;
        if ($unitPrice !== $currentUnitPrice) {
            $action->handle($inquiry, $item, 'unit_price', $unitPrice, $user);
            $item = $item->refresh();
        }

        $notes = trim((string) ($data['editInquiryProductNotes'] ?? ''));
        $currentNotes = trim((string) ($item->notes ?? ''));
        if ($notes !== $currentNotes) {
            $action->handle($inquiry, $item, 'notes', $notes, $user);
        }

        $this->closeEditInquiryProduct();
        session()->flash('success', 'Inquiry product updated.');
    }

    private function inquiryEditProductCategory(\App\Models\MasterRecord $product): string
    {
        $category = trim((string) ($product->parent?->name ?? ''));
        if ($category === '') {
            $legacy = trim((string) $product->description);
            $category = trim(explode(' ·', $legacy, 2)[0]);
        }

        return $category !== '' ? $category : 'Uncategorized';
    }

    #[Json]
    public function updateInquiryItem(int $itemId, string $field, mixed $value): array
    {
        $label = match ($field) {
            'category' => 'product category',
            'item_name' => 'product',
            'quantity' => 'quantity',
            'unit_price' => 'unit price',
            'notes' => 'product notes',
            default => 'product detail',
        };

        return $this->persistInlineEdit($label, function () use ($itemId, $field, $value): void {
            $user = auth()->user();
            $inquiry = $this->selectedInquiry();
            $item = InquiryItem::query()
                ->where('inquiry_id', $inquiry->id)
                ->findOrFail($itemId);

            if ($field === 'category') {
                abort_unless(
                    app(\App\Services\MasterDataService::class)
                        ->active('product_category')
                        ->contains('name', trim((string) $value)),
                    422,
                    'Select a valid active product category.'
                );
            }

            if ($field === 'item_name') {
                abort_if(blank($item->category), 422, 'Select a product category first.');
                $validProduct = app(\App\Services\FilterOptionService::class)
                    ->options($user, 'products', 'inquiry-detail', '', trim((string) $value), 20, [
                        'category' => (string) $item->category,
                    ])
                    ->contains(fn ($option) => (string) ($option['id'] ?? '') === trim((string) $value));
                abort_unless($validProduct, 422, 'Select a valid active product for this category.');
            }

            app(\App\Actions\Inquiries\UpdateInquiryItem::class)->handle($inquiry, $item, $field, $value, $user);
        });
    }

    public function openAddInquiryProductForm(): void
    {
        if ($this->editInquiryProductItemId) {
            $this->closeEditInquiryProduct();
        }
        $user = auth()->user();
        $inquiry = $this->selectedInquiry();
        $access = app(AccessControlService::class);

        abort_unless(
            app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit($user, $inquiry)
            && ! $inquiry->result
            && $access->can($user, 'catalog_products', 'view')
            && $access->can($user, 'catalog_products', 'create'),
            403
        );

        $this->resetValidation([
            'inquiryProductSelectedId', 'inquiryProductCategory', 'inquiryProductQuantity', 'inquiryProductUnitPrice',
        ]);
        $this->inquiryProductSearch = '';
        $this->inquiryProductShowAllResults = false;
        $this->inquiryProductSelectedId = null;
        $this->inquiryProductCategory = '';
        $this->inquiryProductQuantity = '1000';
        $this->inquiryProductUnitPrice = '0.00';
        $this->showAddInquiryProductForm = true;
    }

    public function closeAddInquiryProductForm(): void
    {
        if ($this->showMissingProductSupplierModal && $this->missingProductSupplierContext === 'inquiry_detail') {
            $this->closeMissingProductSupplierModal();
        }
        $this->showAddInquiryProductForm = false;
        $this->inquiryProductSearch = '';
        $this->inquiryProductShowAllResults = false;
        $this->inquiryProductSelectedId = null;
        $this->inquiryProductCategory = '';
        $this->inquiryProductQuantity = '1000';
        $this->inquiryProductUnitPrice = '0.00';
        $this->resetValidation([
            'inquiryProductSelectedId', 'inquiryProductCategory', 'inquiryProductQuantity', 'inquiryProductUnitPrice',
        ]);
    }

    public function showAllInquiryProductResults(): void
    {
        abort_unless($this->showAddInquiryProductForm, 422);
        $this->inquiryProductShowAllResults = true;
    }

    public function selectInquiryProduct(int $productId): void
    {
        abort_unless($this->showAddInquiryProductForm && $this->selectedInquiryId, 422);
        $user = auth()->user();
        $inquiry = $this->selectedInquiry();
        $access = app(AccessControlService::class);

        abort_unless(
            app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit($user, $inquiry)
            && ! $inquiry->result
            && $access->can($user, 'catalog_products', 'view')
            && $access->can($user, 'catalog_products', 'create'),
            403
        );

        $product = app(\App\Services\ProductCatalogService::class)->findActiveProductOrFail($productId);
        $linkedSupplier = app(\App\Services\ProductCatalogService::class)->supplierForProduct($product);
        $category = trim((string) ($product->parent?->name ?? ''));
        if ($category === '') {
            $legacy = trim((string) $product->description);
            $category = trim(explode(' ·', $legacy, 2)[0]);
        }

        $defaultQuantity = 1000;
        $basePrice = $product->productPriceForQuantity($defaultQuantity, (int) $inquiry->client_id);

        $this->inquiryProductSelectedId = (int) $product->id;
        $this->inquiryProductCategory = $category !== '' ? $category : 'Uncategorized';
        $this->inquiryProductSearch = (string) $product->name;
        $this->inquiryProductQuantity = (string) $defaultQuantity;
        $this->inquiryProductUnitPrice = $basePrice !== null ? number_format((float) $basePrice, 2, '.', '') : '0.00';
        $this->resetValidation(['inquiryProductSelectedId', 'inquiryProductCategory', 'inquiryProductQuantity', 'inquiryProductUnitPrice']);

        if (!$linkedSupplier) {
            $this->openMissingProductSupplierModalFor($product, null, 'inquiry_detail', true, 'Inquiry', 'continue');
        }
    }

    public function updatedInquiryProductQuantity(): void
    {
        if (!$this->showAddInquiryProductForm || !$this->inquiryProductSelectedId) return;
        $this->syncDetailInquiryProductBasePrice();
    }

    private function syncDetailInquiryProductBasePrice(): void
    {
        $quantity = (int) $this->inquiryProductQuantity;
        if (!$this->inquiryProductSelectedId || $quantity <= 0) {
            $this->inquiryProductUnitPrice = '0.00';
            $this->resetValidation('inquiryProductUnitPrice');
            return;
        }

        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $this->inquiryProductSelectedId);
        $basePrice = $product->productPriceForQuantity($quantity, (int) $this->selectedInquiry()->client_id);
        $this->inquiryProductUnitPrice = $basePrice !== null
            ? number_format((float) $basePrice, 2, '.', '')
            : '0.00';
        $this->resetValidation('inquiryProductUnitPrice');
    }

    public function saveInquiryProduct(): void
    {
        abort_unless($this->showAddInquiryProductForm, 422);
        $user = auth()->user();
        $inquiry = $this->selectedInquiry();
        $access = app(AccessControlService::class);

        abort_unless(
            app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit($user, $inquiry)
            && ! $inquiry->result
            && $access->can($user, 'catalog_products', 'view')
            && $access->can($user, 'catalog_products', 'create'),
            403
        );

        $data = $this->validate([
            'inquiryProductSelectedId' => ['required', 'integer'],
            'inquiryProductQuantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'inquiryProductUnitPrice' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ], [
            'inquiryProductSelectedId.required' => 'Select a product first.',
            'inquiryProductQuantity.required' => 'Enter a quantity.',
            'inquiryProductUnitPrice.required' => 'Enter a unit price.',
        ]);

        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $data['inquiryProductSelectedId']);
        $category = trim((string) ($product->parent?->name ?? ''));
        if ($category === '') {
            $legacy = trim((string) $product->description);
            $category = trim(explode(' ·', $legacy, 2)[0]);
        }
        if ($category === '') {
            $this->addError('inquiryProductSelectedId', 'This product does not have an active product category.');
            return;
        }

        $alreadyAdded = $inquiry->items()
            ->whereRaw('LOWER(item_name) = ?', [mb_strtolower((string) $product->name)])
            ->exists();
        if ($alreadyAdded) {
            $this->addError('inquiryProductSelectedId', 'This product is already added to the Inquiry.');
            return;
        }

        $basePrice = $product->productPriceForQuantity((int) $data['inquiryProductQuantity'], (int) $inquiry->client_id);
        $resolvedUnitPrice = $basePrice !== null
            ? (float) $basePrice
            : (float) $data['inquiryProductUnitPrice'];

        app(\App\Actions\Inquiries\AddInquiryItem::class)->handle(
            $inquiry,
            $category,
            (string) $product->name,
            (int) $data['inquiryProductQuantity'],
            $user,
            $resolvedUnitPrice,
        );

        $this->closeAddInquiryProductForm();
    }

    // Backwards-compatible entry point for any older UI that still calls this
    // method. The details page now opens the shared search-based Add Product panel.

    public function addInquiryItem(): void
    {
        $this->openAddInquiryProductForm();
    }

    public function removeInquiryItem(int $itemId): void
    {
        $user = auth()->user();
        $inquiry = $this->selectedInquiry();
        $item = InquiryItem::query()
            ->where('inquiry_id', $inquiry->id)
            ->findOrFail($itemId);

        app(\App\Actions\Inquiries\RemoveInquiryItem::class)->handle($inquiry, $item, $user);
        if ((int) ($this->editInquiryProductItemId ?? 0) === $itemId) {
            $this->closeEditInquiryProduct();
        }
    }

    public function beginInquiryProductEdit(): void
    {
        $inquiry = $this->selectedInquiry(['items']);
        abort_unless(auth()->user()->canModule('catalog_products', 'edit'), 403);
        abort_unless(app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit(auth()->user(), $inquiry), 403);
        abort_if($inquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');

        $this->inquiryProductRows = $inquiry->items
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'category' => (string) ($item->category ?? ''),
                'product' => (string) $item->item_name,
                'quantity' => max(1, (int) round((float) $item->quantity)),
                'unit_price' => $item->unit_price !== null ? (string) $item->unit_price : '',
            ])
            ->values()
            ->all();

        if ($this->inquiryProductRows === []) {
            $this->inquiryProductRows = [['id' => null, 'category' => '', 'product' => '', 'quantity' => 1, 'unit_price' => '']];
        }

        $this->inquiryCategoryFilterOptions = app(\App\Services\FilterOptionService::class)
            ->options(auth()->user(), 'product-categories', 'inquiry-detail', '', null, 8)
            ->all();
        $this->editingInquiryProducts = true;
        $this->resetValidation('inquiryProductRows');
    }

    public function cancelInquiryProductEdit(): void
    {
        $this->editingInquiryProducts = false;
        $this->inquiryProductRows = [];
        $this->inquiryCategoryFilterOptions = [];
        $this->resetValidation('inquiryProductRows');
    }

    public function addInquiryProductRow(): void
    {
        abort_unless($this->editingInquiryProducts, 422);
        abort_if(count($this->inquiryProductRows) >= 25, 422, 'An Inquiry can contain up to 25 product rows.');
        $this->inquiryProductRows[] = ['id' => null, 'category' => '', 'product' => '', 'quantity' => 1, 'unit_price' => ''];
    }

    public function removeInquiryProductRow(int $index): void
    {
        abort_unless($this->editingInquiryProducts, 422);
        abort_unless(array_key_exists($index, $this->inquiryProductRows), 422);
        if (count($this->inquiryProductRows) <= 1) return;
        unset($this->inquiryProductRows[$index]);
        $this->inquiryProductRows = array_values($this->inquiryProductRows);
        $this->resetValidation('inquiryProductRows');
    }

    public function setInquiryProductSelector(string $property, mixed $value): void
    {
        abort_unless($this->editingInquiryProducts && $this->selectedInquiryId, 403);
        $inquiry = $this->selectedInquiry();
        abort_unless(auth()->user()->canModule('catalog_products', 'edit'), 403);
        abort_unless(app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit(auth()->user(), $inquiry), 403);
        abort_if($inquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');

        if (preg_match('/^inquiryProductRows\.(\d+)\.(category|product)$/', $property, $matches) !== 1) {
            abort(422, 'Unsupported Inquiry product selector.');
        }

        $index = (int) $matches[1];
        $field = $matches[2];
        abort_unless(array_key_exists($index, $this->inquiryProductRows), 422, 'That product row is no longer available.');

        $raw = trim((string) $value);
        abort_unless($raw !== '', 422, 'Please choose a valid option.');
        $category = $field === 'product' ? trim((string) ($this->inquiryProductRows[$index]['category'] ?? '')) : '';
        $type = $field === 'category' ? 'product-categories' : 'products';
        $valid = app(\App\Services\FilterOptionService::class)->options(
            auth()->user(),
            $type,
            'inquiry-detail',
            '',
            $raw,
            20,
            $field === 'product' ? ['category' => $category] : [],
        )->contains(fn ($item) => (string) ($item['id'] ?? '') === $raw);
        abort_unless($valid, 422, 'That option is no longer available.');

        $this->inquiryProductRows[$index][$field] = $raw;
        $this->resetValidation("inquiryProductRows.$index.$field");

        if ($field === 'category') {
            $this->inquiryProductRows[$index]['product'] = '';
            $this->resetValidation("inquiryProductRows.$index.product");
        }
    }

    public function saveInquiryProducts(): void
    {
        $inquiry = $this->selectedInquiry();
        abort_unless(auth()->user()->canModule('catalog_products', 'edit'), 403);
        abort_unless($this->editingInquiryProducts && app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEdit(auth()->user(), $inquiry), 403);
        abort_if($inquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');

        $data = $this->validate([
            'inquiryProductRows' => ['required', 'array', 'min:1', 'max:25'],
            'inquiryProductRows.*.category' => ['required', 'string', 'max:255'],
            'inquiryProductRows.*.product' => ['required', 'string', 'max:255'],
            'inquiryProductRows.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'inquiryProductRows.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);

        $options = app(\App\Services\FilterOptionService::class);
        $catalogInvalid = false;
        foreach ($data['inquiryProductRows'] as $index => $row) {
            $category = trim((string) $row['category']);
            $product = trim((string) $row['product']);
            $categoryValid = $options->options(auth()->user(), 'product-categories', 'inquiry-detail', '', $category, 20)
                ->contains(fn ($item) => (string) ($item['id'] ?? '') === $category);
            $productValid = $options->options(auth()->user(), 'products', 'inquiry-detail', '', $product, 20, ['category' => $category])
                ->contains(fn ($item) => (string) ($item['id'] ?? '') === $product);

            if (! $categoryValid) {
                $catalogInvalid = true;
                $this->addError("inquiryProductRows.$index.category", 'That product category is no longer available.');
            }
            if (! $productValid) {
                $catalogInvalid = true;
                $this->addError("inquiryProductRows.$index.product", 'That product is not available for the selected category.');
            }
        }
        if ($catalogInvalid) return;

        app(\App\Actions\Inquiries\ReplaceInquiryItems::class)->handle($inquiry, array_map(fn (array $row): array => [
            'category' => trim((string) $row['category']),
            'name' => trim((string) $row['product']),
            'quantity' => (int) $row['quantity'],
            'unit_price' => filled($row['unit_price'] ?? null) ? round((float) $row['unit_price'], 2) : null,
            'unit' => 'pcs',
        ], $data['inquiryProductRows']), auth()->user());

        $this->editingInquiryProducts = false;
        $this->inquiryProductRows = [];
        $this->inquiryCategoryFilterOptions = [];
        session()->flash('success', 'Inquiry products updated.');
    }
}
