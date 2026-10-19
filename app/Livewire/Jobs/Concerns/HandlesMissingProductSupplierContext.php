<?php

namespace App\Livewire\Jobs\Concerns;

use App\Models\MasterRecord;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\MasterDataService;

/** Applies the shared missing-supplier workflow to Create Order and Order Details. */
trait HandlesMissingProductSupplierContext
{
    protected function authorizeMissingProductSupplierContext(string $context): void
    {
        if ($context === 'create_order') {
            $this->authorizeCreateOrderProducts();
            return;
        }

        abort_unless($context === 'order_detail', 422, 'Unsupported Order supplier resolution context.');
        abort_unless($this->showAddJobProductForm && $this->selectedJobId && $this->jobProductSelectedId, 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->detail($user, $this->selectedJobId);
        abort_unless(
            app(AccessControlService::class)->canEditVisibleJob($user, $job)
            && app(AccessControlService::class)->can($user, 'catalog_products', 'view')
            && app(AccessControlService::class)->can($user, 'catalog_products', 'create'),
            403
        );
    }

    protected function assertMissingProductSupplierTargetCurrent(
        MasterRecord $product,
        ?int $rowIndex,
        string $context,
    ): void {
        if ($context === 'create_order') {
            if ($rowIndex === null) {
                return;
            }

            $index = (int) $rowIndex;
            abort_unless(array_key_exists($index, $this->jobItems), 422, 'That product row is no longer available.');
            abort_unless(
                (int) ($this->jobItems[$index]['product_id'] ?? 0) === (int) $product->id,
                422,
                'That product row is no longer available.'
            );
            return;
        }

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
        if ($context === 'create_order') {
            $this->completeCreateOrderMissingProductSupplier($product, $supplierId, $skipped, $rowIndex);
            return;
        }

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

    private function completeCreateOrderMissingProductSupplier(
        MasterRecord $product,
        ?int $supplierId,
        bool $skipped,
        ?int $rowIndex,
    ): void {
        $productId = (int) $product->id;
        unset($this->createOrderSupplierOverrides[$productId]);

        if ($skipped) {
            if (!in_array($productId, $this->createOrderSupplierSkipProductIds, true)) {
                $this->createOrderSupplierSkipProductIds[] = $productId;
            }
        } else {
            $this->createOrderSupplierSkipProductIds = array_values(array_filter(
                $this->createOrderSupplierSkipProductIds,
                fn (int $id): bool => $id !== $productId
            ));
        }

        if ($rowIndex !== null) {
            $index = (int) $rowIndex;
            abort_unless(array_key_exists($index, $this->jobItems), 422, 'That product row is no longer available.');
            abort_unless((int) ($this->jobItems[$index]['product_id'] ?? 0) === $productId, 422, 'That product row is no longer available.');
            $this->jobItems[$index]['supplier_id'] = $supplierId;
            $this->syncCreateOrderProductBasePrice($index);
            $this->resetValidation("jobItems.$index.supplier_id");
        } else {
            $alreadySelected = collect($this->jobItems)->contains(
                fn (array $row): bool => (int) ($row['product_id'] ?? 0) === $productId
            );

            if (!$alreadySelected) {
                abort_if(count($this->jobItems) >= 25, 422, 'An Order can contain up to 25 products.');
                $this->appendCreateOrderProduct($product, $supplierId);
            }
        }

        $this->createProductShowAllResults = false;
        $this->resetValidation('jobItems');
        $this->dispatch('create-order-product-selected');
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
