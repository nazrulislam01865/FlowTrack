<?php

namespace App\Livewire\Jobs;

use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Concerns\ManagesMissingProductSupplier;
use App\Livewire\Jobs\Concerns\ManagesOrderProducts;
use App\Models\MasterRecord;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\MasterDataService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Isolated Order Details product workspace.
 *
 * This component intentionally owns only product-related state. Keeping it out
 * of Jobs\Index prevents a viewport-triggered product load or product edit from
 * hydrating and rerendering the complete Order Details coordinator.
 */
final class OrderProductsSection extends Component
{
    use HandlesInlineEdits;
    use ManagesMissingProductSupplier;
    use ManagesOrderProducts;

    public int $orderId;
    public bool $ready = false;

    /** Compatibility properties expected by the extracted product concern. */
    public ?int $selectedJobId = null;
    public string $detailTab = 'overview';

    public bool $showAddJobProductForm = false;
    public string $jobProductSearch = '';
    public bool $jobProductShowAllResults = false;
    public ?int $jobProductSelectedId = null;
    public string $jobProductCategory = '';
    public string $jobProductQuantity = '1';
    public string $jobProductUnitPrice = '0.00';
    public ?int $jobProductSupplierId = null;
    public string $jobProductSupplierLabel = '';
    public bool $jobProductSupplierSkipped = false;
    public bool $jobProductSupplierLocked = false;

    public bool $showEditOrderProductModal = false;
    public ?int $editOrderProductItemId = null;
    public ?int $editOrderProductSelectedId = null;
    public string $editOrderProductSearch = '';
    public bool $editOrderProductShowAllResults = false;
    public string $editOrderProductName = '';
    public string $editOrderProductCode = '';
    public string $editOrderProductCategory = '';
    public ?int $editOrderProductSupplierId = null;
    public string $editOrderProductSupplierLabel = '';
    public string $editOrderProductQuantity = '1';
    public string $editOrderProductUnitPrice = '0.00';
    public string $editOrderProductNotes = '';

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->selectedJobId = $orderId;
    }

    public function loadProductsSection(
        string $section,
        ?string $contextType = null,
        ?int $contextId = null,
    ): void {
        if ($section !== 'products' || $contextType !== 'order' || (int) $contextId !== $this->orderId) {
            return;
        }

        $this->ready = true;
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // A normal child-component rerender refreshes product rows only.
    }

    public function render()
    {
        if (! $this->ready) {
            return view('livewire.jobs.order-products-section', [
                'job' => null,
                'context' => [],
            ]);
        }

        $user = auth()->user();
        $orderQuery = app(VisibleOrderQuery::class);
        $job = $orderQuery->scoped(
            $user,
            $this->orderId,
            ['owner:id,name,profile_image_path'],
            ['id', 'currency', 'owner_id', 'coordinator_id', 'created_by', 'product', 'category', 'quantity'],
        );
        $orderQuery->loadOverviewProducts($job, $user);

        $access = app(AccessControlService::class);
        $canEdit = $access->canEditVisibleJob($user, $job);
        $canViewProducts = $access->can($user, 'catalog_products', 'view');

        $context = [
            'canViewProducts' => $canViewProducts,
            'canEditProducts' => $canEdit
                && $canViewProducts
                && $access->can($user, 'catalog_products', 'edit'),
            'canCreateProducts' => $canEdit
                && $canViewProducts
                && $access->can($user, 'catalog_products', 'create'),
            'canDeleteProducts' => $canEdit
                && $canViewProducts
                && $access->can($user, 'catalog_products', 'delete'),
        ];

        $jobProductSearchResults = collect();
        $jobProductSearchSuppliers = collect();
        $jobProductResultTotal = 0;
        $jobProductSelectedProduct = null;
        $jobProductSelectedSupplier = null;

        if ($this->showAddJobProductForm) {
            $catalog = app(\App\Services\ProductCatalogService::class);
            $productSearch = trim($this->jobProductSearch);
            $jobProductResultTotal = $catalog->orderSearchCount($productSearch, null);
            $resultLimit = $this->jobProductShowAllResults || $jobProductResultTotal <= 20 ? 20 : 3;
            $jobProductSearchResults = $catalog->searchForOrderCreation($productSearch, null, $resultLimit);
            $jobProductSearchSuppliers = $catalog->suppliersForProducts($jobProductSearchResults->keyBy('id'));

            if ($this->jobProductSelectedId) {
                $jobProductSelectedProduct = $catalog->selectedProducts([$this->jobProductSelectedId])->first();
                $jobProductSelectedSupplier = $jobProductSelectedProduct
                    ? $catalog->supplierForProduct($jobProductSelectedProduct)
                    : null;
            }
        }

        $editOrderProductSearchResults = collect();
        $editOrderProductSearchSuppliers = collect();
        $editOrderProductResultTotal = 0;
        $editOrderProductSelectedProduct = null;
        $editOrderProductSelectedSupplier = null;

        if ($this->showEditOrderProductModal && $this->editOrderProductItemId) {
            $catalog = app(\App\Services\ProductCatalogService::class);
            $productSearch = trim($this->editOrderProductSearch);
            $editOrderProductResultTotal = $catalog->orderSearchCount($productSearch, null);
            $resultLimit = $this->editOrderProductShowAllResults || $editOrderProductResultTotal <= 20 ? 20 : 3;
            $editOrderProductSearchResults = $catalog->searchForOrderCreation($productSearch, null, $resultLimit);
            $editOrderProductSearchSuppliers = $catalog->suppliersForProducts($editOrderProductSearchResults->keyBy('id'));

            if ($this->editOrderProductSelectedId) {
                $editOrderProductSelectedProduct = $catalog->selectedProducts([$this->editOrderProductSelectedId])->first();
            }

            if ($this->editOrderProductSupplierId) {
                $editOrderProductSelectedSupplier = MasterRecord::query()
                    ->forWorkspace(app(MasterDataService::class)->workspaceId())
                    ->ofType('supplier')
                    ->active()
                    ->find((int) $this->editOrderProductSupplierId, ['id', 'name', 'code', 'status']);
            }
        }

        return view('livewire.jobs.order-products-section', [
            'job' => $job,
            'context' => $context,
            'jobProductSearchResults' => $jobProductSearchResults,
            'jobProductSearchSuppliers' => $jobProductSearchSuppliers,
            'jobProductResultTotal' => $jobProductResultTotal,
            'jobProductSelectedProduct' => $jobProductSelectedProduct,
            'jobProductSelectedSupplier' => $jobProductSelectedSupplier,
            'editOrderProductSearchResults' => $editOrderProductSearchResults,
            'editOrderProductSearchSuppliers' => $editOrderProductSearchSuppliers,
            'editOrderProductResultTotal' => $editOrderProductResultTotal,
            'editOrderProductSelectedProduct' => $editOrderProductSelectedProduct,
            'editOrderProductSelectedSupplier' => $editOrderProductSelectedSupplier,
        ]);
    }

    protected function authorizeMissingProductSupplierContext(string $context): void
    {
        abort_unless($context === 'order_detail', 422, 'Unsupported Order supplier resolution context.');
        abort_unless($this->showAddJobProductForm && $this->selectedJobId && $this->jobProductSelectedId, 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->scoped(
            $user,
            $this->selectedJobId,
            [],
            ['id', 'owner_id', 'coordinator_id', 'created_by'],
        );
        $access = app(AccessControlService::class);

        abort_unless(
            $access->canEditVisibleJob($user, $job)
            && $access->can($user, 'catalog_products', 'view')
            && $access->can($user, 'catalog_products', 'create'),
            403
        );
    }

    protected function assertMissingProductSupplierTargetCurrent(
        MasterRecord $product,
        ?int $rowIndex,
        string $context,
    ): void {
        abort_unless($context === 'order_detail', 422, 'Unsupported Order supplier resolution context.');
        abort_unless(
            (int) $this->jobProductSelectedId === (int) $product->id,
            422,
            'That selected product is no longer available.'
        );
    }

    protected function completeMissingProductSupplierContext(
        MasterRecord $product,
        ?int $supplierId,
        bool $skipped,
        ?int $rowIndex,
        string $context,
    ): void {
        abort_unless($context === 'order_detail', 422, 'Unsupported Order supplier resolution context.');
        abort_unless((int) $this->jobProductSelectedId === (int) $product->id, 422, 'That selected product is no longer available.');

        if ($skipped) {
            $this->jobProductSupplierId = null;
            $this->jobProductSupplierLabel = '';
            $this->jobProductSupplierSkipped = true;
            $this->jobProductSupplierLocked = false;
            $this->resetValidation('jobProductSupplierId');
            $this->dispatch('create-order-product-supplier-selected');
            return;
        }

        abort_unless($supplierId, 422, 'Select or create a supplier, or continue without one.');

        $supplier = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId, ['id', 'name', 'metadata', 'type']);

        $this->jobProductSupplierId = (int) $supplier->id;
        $this->jobProductSupplierLabel = $supplier->supplierShortCode();
        $this->jobProductSupplierSkipped = false;
        $this->jobProductSupplierLocked = false;
        $this->resetValidation('jobProductSupplierId');
        $this->dispatch('create-order-product-supplier-selected');
    }

    public function openJobProductSupplierResolution(): void
    {
        $this->authorizeMissingProductSupplierContext('order_detail');
        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $this->jobProductSelectedId);
        $linkedSupplier = app(\App\Services\ProductCatalogService::class)->supplierForProduct($product);

        if ($linkedSupplier) {
            $this->jobProductSupplierId = (int) $linkedSupplier->id;
            $this->jobProductSupplierLabel = $linkedSupplier->supplierShortCode();
            $this->jobProductSupplierSkipped = false;
            $this->resetValidation('jobProductSupplierId');
            return;
        }

        $this->openMissingProductSupplierModalFor($product, null, 'order_detail', true, 'Order', 'continue');
    }
}
