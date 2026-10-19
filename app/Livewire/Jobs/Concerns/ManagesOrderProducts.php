<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\AddOrderItem;
use App\Actions\Orders\RemoveOrderItem;
use App\Actions\Orders\RestoreOrderItem;
use App\Actions\Orders\UpdateOrderItemDetails;
use App\Actions\Orders\UpdateOrderItemField;
use App\Queries\Orders\VisibleOrderQuery;
use App\Models\FlowJobItem;
use App\Models\MasterRecord;
use App\Services\AccessControlService;
use App\Services\MasterDataService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderProducts
{
    /**
     * Persist a supplier selected by the reusable remote search control.
     *
     * The UI passes a namespaced property token rather than binding a temporary
     * Livewire field per row. This keeps product rows lightweight while still
     * routing the update through the same authorization/audit service path.
     */
    public function openEditOrderProductModal(int $itemId): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        if ($this->showAddJobProductForm) {
            $this->closeAddJobProductForm();
        }

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->can($user, 'catalog_products', 'edit'), 403);

        $item = FlowJobItem::query()
            ->where('flow_job_id', $job->id)
            ->with(['supplier:id,name,code,metadata,type,status'])
            ->findOrFail($itemId);
        abort_if((bool) ($item->is_removed ?? false), 422, 'Restore this product before editing it.');

        $catalog = app(\App\Services\ProductCatalogService::class);
        $product = null;
        if ((int) ($item->catalog_product_id ?? 0) > 0) {
            $product = $catalog->selectedProducts([(int) $item->catalog_product_id])->first();
        }
        if (!$product && filled($item->product_name)) {
            $productQuery = $catalog->activeProductsQuery()
                ->with(['parent' => fn ($parent) => $parent->where('type', 'product_category')])
                ->where('name', trim((string) $item->product_name));
            if (filled($item->category_name)) {
                $productQuery->whereHas('parent', fn ($parent) => $parent
                    ->where('type', 'product_category')
                    ->where('name', trim((string) $item->category_name)));
            }
            $product = $productQuery->first();
        }

        $quantity = max(1, (int) ($item->quantity ?? 1));
        $defaultSupplier = $product ? $catalog->supplierForProduct($product) : null;
        $selectedSupplier = $item->supplier ?: $defaultSupplier;
        $basePrice = $product?->productPriceForQuantity($quantity, (int) $job->client_id);

        $this->editOrderProductItemId = (int) $item->id;
        $this->editOrderProductSelectedId = $product ? (int) $product->id : null;
        $this->editOrderProductSearch = '';
        $this->editOrderProductShowAllResults = false;
        $this->editOrderProductName = (string) ($product?->name ?: $item->product_name ?: '');
        $this->editOrderProductCode = (string) ($product?->productDisplayCode() ?: '');
        $this->editOrderProductCategory = $product
            ? $this->orderEditProductCategory($product)
            : (string) ($item->category_name ?? '');
        $this->editOrderProductSupplierId = $selectedSupplier ? (int) $selectedSupplier->id : null;
        $this->editOrderProductSupplierLabel = $selectedSupplier?->supplierShortCode() ?: '';
        $this->editOrderProductQuantity = (string) $quantity;
        $this->editOrderProductUnitPrice = number_format(
            $basePrice !== null ? (float) $basePrice : (float) ($item->unit_price ?? 0),
            2,
            '.',
            ''
        );
        $this->editOrderProductNotes = (string) ($item->notes ?? '');
        $this->showEditOrderProductModal = true;
        $this->resetValidation([
            'editOrderProductSelectedId',
            'editOrderProductSupplierId',
            'editOrderProductQuantity',
            'editOrderProductUnitPrice',
            'editOrderProductNotes',
        ]);
    }

    public function closeEditOrderProductModal(): void
    {
        $this->showEditOrderProductModal = false;
        $this->editOrderProductItemId = null;
        $this->editOrderProductSelectedId = null;
        $this->editOrderProductSearch = '';
        $this->editOrderProductShowAllResults = false;
        $this->editOrderProductName = '';
        $this->editOrderProductCode = '';
        $this->editOrderProductCategory = '';
        $this->editOrderProductSupplierId = null;
        $this->editOrderProductSupplierLabel = '';
        $this->editOrderProductQuantity = '1';
        $this->editOrderProductUnitPrice = '0.00';
        $this->editOrderProductNotes = '';
        $this->resetValidation([
            'editOrderProductSelectedId',
            'editOrderProductSupplierId',
            'editOrderProductQuantity',
            'editOrderProductUnitPrice',
            'editOrderProductNotes',
        ]);
    }

    public function showAllEditOrderProductResults(): void
    {
        abort_unless($this->showEditOrderProductModal && $this->editOrderProductItemId, 422);
        $this->editOrderProductShowAllResults = true;
    }

    public function updatedEditOrderProductSearch(): void
    {
        if (!$this->showEditOrderProductModal || !$this->editOrderProductItemId) {
            return;
        }

        $this->editOrderProductShowAllResults = false;

        // As soon as the user starts searching for a replacement product, the
        // dependent Product Master fields must not keep showing stale values.
        // They are populated again only after a search result is selected.
        if (
            $this->editOrderProductSelectedId
            && strcasecmp(trim($this->editOrderProductSearch), trim($this->editOrderProductName)) !== 0
        ) {
            $this->editOrderProductSelectedId = null;
            $this->editOrderProductName = '';
            $this->editOrderProductCode = '';
            $this->editOrderProductCategory = '';
            $this->editOrderProductSupplierId = null;
            $this->editOrderProductSupplierLabel = '';
            $this->editOrderProductUnitPrice = '0.00';
            $this->resetValidation([
                'editOrderProductSelectedId',
                'editOrderProductSupplierId',
                'editOrderProductUnitPrice',
            ]);
        }
    }

    public function selectEditOrderProduct(int $productId): void
    {
        abort_unless($this->showEditOrderProductModal && $this->selectedJobId && $this->editOrderProductItemId, 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        abort_unless(app(AccessControlService::class)->can($user, 'catalog_products', 'edit'), 403);

        $catalog = app(\App\Services\ProductCatalogService::class);
        $product = $catalog->findActiveProductOrFail($productId);
        $supplier = $catalog->supplierForProduct($product);
        $quantity = max(1, (int) $this->editOrderProductQuantity);
        $basePrice = $product->productPriceForQuantity($quantity, (int) $job->client_id);

        $this->editOrderProductSelectedId = (int) $product->id;
        $this->editOrderProductSearch = (string) $product->name;
        $this->editOrderProductShowAllResults = false;
        $this->editOrderProductName = (string) $product->name;
        $this->editOrderProductCode = (string) $product->productDisplayCode();
        $this->editOrderProductCategory = $this->orderEditProductCategory($product);
        $this->editOrderProductSupplierId = $supplier ? (int) $supplier->id : null;
        $this->editOrderProductSupplierLabel = $supplier?->supplierShortCode() ?: '';
        $this->editOrderProductUnitPrice = $basePrice !== null
            ? number_format((float) $basePrice, 2, '.', '')
            : '0.00';

        $this->resetValidation([
            'editOrderProductSelectedId',
            'editOrderProductSupplierId',
            'editOrderProductQuantity',
            'editOrderProductUnitPrice',
        ]);
        $this->dispatch('detail-product-edit-selected');
    }

    public function updatedEditOrderProductQuantity(): void
    {
        if (!$this->showEditOrderProductModal || !$this->editOrderProductSelectedId) {
            return;
        }

        $quantity = (int) $this->editOrderProductQuantity;
        if ($quantity <= 0) {
            $this->editOrderProductUnitPrice = '0.00';
            return;
        }

        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $this->editOrderProductSelectedId);
        $basePrice = $product->productPriceForQuantity($quantity, (int) app(VisibleOrderQuery::class)->base(auth()->user(), $this->selectedJobId)->client_id);
        $this->editOrderProductUnitPrice = $basePrice !== null
            ? number_format((float) $basePrice, 2, '.', '')
            : '0.00';
        $this->resetValidation('editOrderProductUnitPrice');
    }

    #[Renderless]
    public function updateEditOrderProductSupplierFromSelector(string $property, mixed $supplierId): array
    {
        abort_unless($property === 'editOrderProductSupplierId', 422, 'Invalid supplier target.');
        abort_unless($this->showEditOrderProductModal && $this->editOrderProductSelectedId, 422, 'Select a product first.');
        $supplierId = filled($supplierId) ? (int) $supplierId : null;
        abort_unless($supplierId, 422, 'Select a supplier for this product.');

        $supplier = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId);

        $this->editOrderProductSupplierId = (int) $supplier->id;
        $this->editOrderProductSupplierLabel = $supplier->supplierShortCode();
        $this->resetValidation('editOrderProductSupplierId');
        $this->dispatch('detail-product-edit-supplier-selected');

        return ['ok' => true, 'value' => (string) $supplier->id, 'label' => $supplier->supplierShortCode()];
    }

    public function saveEditOrderProductModal(): void
    {
        abort_unless($this->selectedJobId && $this->editOrderProductItemId, 422);

        $data = $this->validate([
            'editOrderProductSelectedId' => ['required', 'integer', 'min:1'],
            'editOrderProductSupplierId' => ['required', 'integer', 'min:1'],
            'editOrderProductQuantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'editOrderProductUnitPrice' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'editOrderProductNotes' => ['nullable', 'string', 'max:2000'],
        ], [
            'editOrderProductSelectedId.required' => 'Search for and select a product first.',
            'editOrderProductSupplierId.required' => 'Select a supplier for this product.',
            'editOrderProductQuantity.required' => 'Enter a quantity.',
        ]);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        $item = FlowJobItem::query()->where('flow_job_id', $job->id)->findOrFail($this->editOrderProductItemId);
        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $data['editOrderProductSelectedId']);

        $duplicate = $job->items()
            ->where('id', '!=', $item->id)
            ->where('is_removed', false)
            ->where(function ($query) use ($product): void {
                $query->where('catalog_product_id', (int) $product->id)
                    ->orWhereRaw('LOWER(product_name) = ?', [mb_strtolower((string) $product->name)]);
            })
            ->exists();
        if ($duplicate) {
            $this->addError('editOrderProductSelectedId', 'This product is already added to the Order.');
            return;
        }

        $basePrice = $product->productPriceForQuantity((int) $data['editOrderProductQuantity'], (int) $job->client_id);
        $resolvedUnitPrice = $basePrice !== null
            ? (float) $basePrice
            : (float) $data['editOrderProductUnitPrice'];

        app(UpdateOrderItemDetails::class)->handle($job, $item, [
            'catalog_product_id' => (int) $product->id,
            'supplier_id' => (int) $data['editOrderProductSupplierId'],
            'quantity' => (int) $data['editOrderProductQuantity'],
            'unit_price' => $resolvedUnitPrice,
            'notes' => trim((string) ($data['editOrderProductNotes'] ?? '')),
        ], $user);

        $this->closeEditOrderProductModal();
        session()->flash('success', 'Order product updated.');
    }

    private function orderEditProductCategory(MasterRecord $product): string
    {
        $category = trim((string) ($product->parent?->name ?? ''));
        if ($category === '') {
            $legacy = trim((string) $product->description);
            $category = trim(explode(' ·', $legacy, 2)[0]);
        }

        return $category !== '' ? $category : 'Uncategorized';
    }

    #[Json]
    public function updateJobItemSupplierFromSelector(string $property, mixed $supplierId): array
    {
        abort_unless(preg_match('/^job-item-supplier:(\d+)$/', $property, $matches) === 1, 422, 'Invalid product supplier target.');

        return $this->updateJobItem((int) $matches[1], 'supplier_id', $supplierId);
    }

    #[Json]
    public function updateJobItem(int $itemId, string $field, mixed $value): array
    {
        $label = match ($field) {
            'category_name' => 'product category',
            'product_name' => 'product',
            'quantity' => 'quantity',
            'unit_price' => 'unit price',
            'notes' => 'product notes',
            'supplier_id' => 'supplier',
            default => 'product detail',
        };

        return $this->persistInlineEdit($label, function () use ($itemId, $field, $value) {
            $user = auth()->user();
            abort_unless($this->selectedJobId, 422);

            $job = app(VisibleOrderQuery::class)->detail($user, $this->selectedJobId);
            $item = FlowJobItem::where('flow_job_id', $job->id)->findOrFail($itemId);

            if ($field === 'category_name') {
                abort_unless(app(MasterDataService::class)->active('product_category')->contains('name', (string) $value), 422, 'Select a valid active product category.');
            }
            if ($field === 'product_name') {
                abort_if(blank($item->category_name), 422, 'Select a product category first.');
                $validProduct = app(\App\Services\FilterOptionService::class)
                    ->options($user, 'products', 'job-detail', '', (string) $value, 20, [
                        'category' => (string) $item->category_name,
                    ])
                    ->contains(fn ($option) => (string) ($option['id'] ?? '') === (string) $value);
                abort_unless($validProduct, 422, 'Select a valid active product for this category.');
            }

            app(UpdateOrderItemField::class)->handle($job, $item, $field, $value, $user);
        });
    }

    public function openAddJobProductForm(int $jobId): void
    {
        if ($this->showEditOrderProductModal) {
            $this->closeEditOrderProductModal();
        }
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->detail($user, $jobId);
        abort_unless(
            app(AccessControlService::class)->canEditVisibleJob($user, $job)
            && app(AccessControlService::class)->can($user, 'catalog_products', 'view')
            && app(AccessControlService::class)->can($user, 'catalog_products', 'create'),
            403
        );

        $this->resetValidation([
            'jobProductSelectedId', 'jobProductCategory', 'jobProductQuantity', 'jobProductUnitPrice', 'jobProductSupplierId',
        ]);
        $this->jobProductSearch = '';
        $this->jobProductShowAllResults = false;
        $this->jobProductSelectedId = null;
        $this->jobProductCategory = '';
        $this->jobProductQuantity = '1000';
        $this->jobProductUnitPrice = '0.00';
        $this->jobProductSupplierId = null;
        $this->jobProductSupplierLabel = '';
        $this->jobProductSupplierSkipped = false;
        $this->jobProductSupplierLocked = false;
        $this->showAddJobProductForm = true;
    }

    public function closeAddJobProductForm(): void
    {
        if ($this->showMissingProductSupplierModal && $this->missingProductSupplierContext === 'order_detail') {
            $this->closeMissingProductSupplierModal();
        }
        $this->showAddJobProductForm = false;
        $this->jobProductSearch = '';
        $this->jobProductShowAllResults = false;
        $this->jobProductSelectedId = null;
        $this->jobProductCategory = '';
        $this->jobProductQuantity = '1000';
        $this->jobProductUnitPrice = '0.00';
        $this->jobProductSupplierId = null;
        $this->jobProductSupplierLabel = '';
        $this->jobProductSupplierSkipped = false;
        $this->jobProductSupplierLocked = false;
        $this->resetValidation([
            'jobProductSelectedId', 'jobProductCategory', 'jobProductQuantity', 'jobProductUnitPrice', 'jobProductSupplierId',
        ]);
    }

    public function showAllJobProductResults(): void
    {
        abort_unless($this->showAddJobProductForm, 422);
        $this->jobProductShowAllResults = true;
    }

    public function selectJobProduct(int $productId): void
    {
        abort_unless($this->showAddJobProductForm && $this->selectedJobId, 422);
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->detail($user, $this->selectedJobId);
        abort_unless(
            app(AccessControlService::class)->canEditVisibleJob($user, $job)
            && app(AccessControlService::class)->can($user, 'catalog_products', 'view')
            && app(AccessControlService::class)->can($user, 'catalog_products', 'create'),
            403
        );

        $product = app(\App\Services\ProductCatalogService::class)->findActiveProductOrFail($productId);
        $category = trim((string) ($product->parent?->name ?? ''));
        if ($category === '') {
            $legacy = trim((string) $product->description);
            $category = trim(explode(' ·', $legacy, 2)[0]);
        }

        $linkedSupplier = app(\App\Services\ProductCatalogService::class)->supplierForProduct($product);

        $defaultQuantity = 1000;
        $basePrice = $product->productPriceForQuantity($defaultQuantity, (int) $job->client_id);

        $this->jobProductSelectedId = (int) $product->id;
        $this->jobProductCategory = $category !== '' ? $category : 'Uncategorized';
        $this->jobProductSearch = (string) $product->name;
        $this->jobProductQuantity = (string) $defaultQuantity;
        $this->jobProductUnitPrice = $basePrice !== null ? number_format((float) $basePrice, 2, '.', '') : '0.00';
        $this->jobProductSupplierId = $linkedSupplier ? (int) $linkedSupplier->id : null;
        $this->jobProductSupplierLabel = $linkedSupplier?->supplierShortCode() ?: '';
        $this->jobProductSupplierSkipped = false;
        // Match Create Order: a linked supplier is the default for this row,
        // but the user may change the supplier for this Order only.
        $this->jobProductSupplierLocked = false;
        $this->resetValidation(['jobProductSelectedId', 'jobProductCategory', 'jobProductSupplierId', 'jobProductQuantity', 'jobProductUnitPrice']);

        if (!$linkedSupplier) {
            // Mirror Create Order: users may link/create a supplier or explicitly
            // continue without one and assign it later from Order Details.
            $this->openMissingProductSupplierModalFor($product, null, 'order_detail', true, 'Order', 'continue');
        }
    }

    #[Renderless]
    public function updateAddJobProductSupplierFromSelector(string $property, mixed $supplierId): array
    {
        abort_unless($property === 'jobProductSupplierId', 422, 'Invalid supplier target.');
        abort_unless($this->showAddJobProductForm && $this->selectedJobId && $this->jobProductSelectedId, 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->detail($user, $this->selectedJobId);
        abort_unless(
            app(AccessControlService::class)->canEditVisibleJob($user, $job)
            && app(AccessControlService::class)->can($user, 'catalog_products', 'view')
            && app(AccessControlService::class)->can($user, 'catalog_products', 'create'),
            403
        );

        // The Product Master supplier is only the default. Match Create Order
        // by allowing a per-Order supplier override without modifying Product Master.
        $supplierId = filled($supplierId) ? (int) $supplierId : null;
        abort_unless($supplierId, 422, 'Select a supplier for this product.');

        $supplier = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId);

        $this->jobProductSupplierId = (int) $supplier->id;
        $this->jobProductSupplierLabel = $supplier->supplierShortCode();
        $this->jobProductSupplierSkipped = false;
        $this->jobProductSupplierLocked = false;
        $this->resetValidation('jobProductSupplierId');
        $this->dispatch('create-order-product-supplier-selected');

        return ['ok' => true, 'value' => (string) $supplier->id, 'label' => $supplier->supplierShortCode()];
    }

    public function updatedJobProductQuantity(): void
    {
        if (!$this->showAddJobProductForm || !$this->jobProductSelectedId) return;
        $this->syncDetailOrderProductBasePrice();
    }

    private function syncDetailOrderProductBasePrice(): void
    {
        $quantity = (int) $this->jobProductQuantity;
        if (!$this->jobProductSelectedId || $quantity <= 0) {
            $this->jobProductUnitPrice = '0.00';
            $this->resetValidation('jobProductUnitPrice');
            return;
        }

        $product = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->active()
            ->find((int) $this->jobProductSelectedId);

        $basePrice = $product?->productPriceForQuantity($quantity, (int) app(VisibleOrderQuery::class)->base(auth()->user(), $this->selectedJobId)->client_id);
        $this->jobProductUnitPrice = $basePrice !== null
            ? number_format((float) $basePrice, 2, '.', '')
            : '0.00';
        $this->resetValidation('jobProductUnitPrice');
    }

    public function saveJobProduct(int $jobId): void
    {
        abort_unless($this->showAddJobProductForm, 422);
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->detail($user, $jobId);
        abort_unless((int) $this->selectedJobId === (int) $job->id, 422);
        abort_unless(
            app(AccessControlService::class)->canEditVisibleJob($user, $job)
            && app(AccessControlService::class)->can($user, 'catalog_products', 'view')
            && app(AccessControlService::class)->can($user, 'catalog_products', 'create'),
            403
        );

        $data = $this->validate([
            'jobProductSelectedId' => ['required', 'integer'],
            'jobProductSupplierId' => [
                Rule::requiredIf(fn (): bool => ! $this->jobProductSupplierSkipped),
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'supplier')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'jobProductQuantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'jobProductUnitPrice' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
        ], [
            'jobProductSelectedId.required' => 'Select a product first.',
            'jobProductSupplierId.required' => 'Select a supplier for this product.',
            'jobProductQuantity.required' => 'Enter a quantity.',
            'jobProductUnitPrice.required' => 'Enter a unit price.',
        ]);

        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $data['jobProductSelectedId']);
        $category = trim((string) ($product->parent?->name ?? ''));
        if ($category === '') {
            $legacy = trim((string) $product->description);
            $category = trim(explode(' ·', $legacy, 2)[0]);
        }
        $category = $category !== '' ? $category : 'Uncategorized';

        $alreadyAdded = $job->items()
            ->whereRaw('LOWER(product_name) = ?', [mb_strtolower((string) $product->name)])
            ->exists();
        if ($alreadyAdded) {
            $this->addError('jobProductSelectedId', 'This product is already added to the Order.');
            return;
        }

        $supplierId = filled($data['jobProductSupplierId'] ?? null)
            ? (int) $data['jobProductSupplierId']
            : null;
        $basePrice = $product->productPriceForQuantity((int) $data['jobProductQuantity'], (int) $job->client_id);
        $resolvedUnitPrice = $basePrice !== null
            ? (float) $basePrice
            : (float) $data['jobProductUnitPrice'];

        app(AddOrderItem::class)->handle(
            $job,
            $category,
            (string) $product->name,
            (int) $data['jobProductQuantity'],
            $user,
            $resolvedUnitPrice,
            (int) $data['jobProductSelectedId'],
            $supplierId,
        );

        $this->closeAddJobProductForm();
    }

    public function addJobItem(int $jobId): void
    {
        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->detail($user, $jobId);

        // Keep a single unfinished row at a time so repeated clicks cannot
        // accumulate invisible/partial product records.
        if ($job->items()->where(fn ($query) => $query
            ->whereNull('product_name')
            ->orWhere('product_name', '')
        )->exists()) {
            return;
        }

        // Add an intentionally blank draft row. The Job Details view renders
        // category, product, and quantity as editable controls immediately so
        // the user chooses the actual values instead of receiving master-data defaults.
        app(AddOrderItem::class)->handle($job, '', '', 1, $user);
    }

    public function removeJobItem(int $itemId): void
    {
        abort_unless($this->selectedJobId, 422);
        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $item = FlowJobItem::where('flow_job_id', $job->id)->findOrFail($itemId);
        app(RemoveOrderItem::class)->handle($job, $item, auth()->user());
        if ((int) ($this->editOrderProductItemId ?? 0) === $itemId) {
            $this->closeEditOrderProductModal();
        }
    }

    public function restoreJobItem(int $itemId): void
    {
        abort_unless($this->selectedJobId, 422);
        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $item = FlowJobItem::where('flow_job_id', $job->id)->findOrFail($itemId);
        app(RestoreOrderItem::class)->handle($job, $item, auth()->user());
    }

}
