<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\MasterRecord;
use App\Services\AccessControlService;

/** Applies the shared missing-supplier workflow to Create/Detail Inquiry product flows. */
trait HandlesMissingProductSupplierContext
{
    protected function authorizeMissingProductSupplierContext(string $context): void
    {
        if ($context === 'create_inquiry') {
            $this->authorizeCreateInquiryProducts();
            return;
        }

        abort_unless($context === 'inquiry_detail', 422, 'Unsupported Inquiry supplier resolution context.');
        abort_unless($this->showAddInquiryProductForm && $this->selectedInquiryId && $this->inquiryProductSelectedId, 422);

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
    }

    protected function assertMissingProductSupplierTargetCurrent(
        MasterRecord $product,
        ?int $rowIndex,
        string $context,
    ): void {
        if ($context === 'create_inquiry') {
            return;
        }

        abort_unless($context === 'inquiry_detail', 422, 'Unsupported Inquiry supplier resolution context.');
        abort_unless(
            (int) $this->inquiryProductSelectedId === (int) $product->id,
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
        if ($context === 'create_inquiry') {
            $alreadySelected = collect($this->createProductRows)->contains(
                fn (array $row): bool => (int) ($row['product_id'] ?? 0) === (int) $product->id
            );

            if (!$alreadySelected) {
                abort_if(count($this->createProductRows) >= 25, 422, 'An Inquiry can contain up to 25 products.');
                $this->appendCreateInquiryProduct($product);
            }

            $this->createProductSearch = '';
            $this->createProductShowAllResults = false;
            $this->resetValidation('createProductRows');
            $this->dispatch('create-order-product-selected');
            return;
        }

        abort_unless($context === 'inquiry_detail', 422, 'Unsupported Inquiry supplier resolution context.');
        abort_unless((int) $this->inquiryProductSelectedId === (int) $product->id, 422, 'That selected product is no longer available.');
        $this->resetValidation('inquiryProductSelectedId');
        $this->dispatch('create-order-product-supplier-selected');
    }
    public function openInquiryProductSupplierResolution(): void
    {
        $this->authorizeMissingProductSupplierContext('inquiry_detail');
        $product = app(\App\Services\ProductCatalogService::class)
            ->findActiveProductOrFail((int) $this->inquiryProductSelectedId);

        if (app(\App\Services\ProductCatalogService::class)->supplierForProduct($product)) {
            return;
        }

        $this->openMissingProductSupplierModalFor($product, null, 'inquiry_detail', true, 'Inquiry', 'continue');
    }


}
