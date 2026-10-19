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

trait ManagesProductAssetsAndOptions
{
    public function updatedProductImage(): void
    {
        abort_unless($this->group === 'product', 404);
        $this->removeProductImage = false;
        $this->validateOnly('productImage', [
            'productImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    public function markProductImageForRemoval(): void
    {
        abort_unless($this->group === 'product', 404);
        $this->removeProductImage = true;
        $this->productImage = null;
        $this->resetValidation('productImage');
    }

    public function restoreProductImage(): void
    {
        $this->removeProductImage = false;
    }

    public function updatedProductCertificateUpload(): void
    {
        $this->removeProductCertificate = false;
        $this->validateOnly('productCertificateUpload', ['productCertificateUpload' => AttachmentUpload::nullableRules(AttachmentUpload::PRODUCT_SUPPORTING, 10240)]);
    }

    public function updatedProductTemplateUpload(): void
    {
        $this->removeProductTemplate = false;
        $this->validateOnly('productTemplateUpload', ['productTemplateUpload' => AttachmentUpload::nullableRules(AttachmentUpload::PRODUCT_SUPPORTING, 10240)]);
    }

    public function clearProductCertificateUpload(): void
    {
        $this->productCertificateUpload = null;
        $this->resetValidation('productCertificateUpload');
    }

    public function clearProductTemplateUpload(): void
    {
        $this->productTemplateUpload = null;
        $this->resetValidation('productTemplateUpload');
    }

    public function removeProductCertificate(): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        $this->productCertificateUpload = null;
        $this->removeProductCertificate = true;
        $this->resetValidation('productCertificateUpload');
    }

    public function removeProductTemplate(): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        $this->productTemplateUpload = null;
        $this->removeProductTemplate = true;
        $this->resetValidation('productTemplateUpload');
    }

    public function addProductOption(): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        if (count($this->productOptions) >= 30) {
            $this->addError('productOptions', 'A product can have up to 30 options.');
            return;
        }

        $this->productOptions[] = [
            'key' => (string) Str::uuid(),
            'label' => '',
            'extra_charge' => '',
            'image_url' => null,
        ];
        $this->resetValidation('productOptions');
    }

    public function removeProductOption(int $index): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        if (! array_key_exists($index, $this->productOptions)) return;

        unset($this->productOptions[$index], $this->productOptionUploads[$index]);
        $this->productOptions = array_values($this->productOptions);
        $this->productOptionUploads = array_values($this->productOptionUploads);
        $this->resetValidation('productOptions');
        $this->resetValidation('productOptionUploads');
    }

    public function addProductShipmentUrgency(): void
    {
        // Keep this public action as the reusable entry point used by the form.
        // Adding is now handled through the card picker instead of an empty select row.
        $this->openProductShipmentUrgencyPicker();
    }

    public function openProductShipmentUrgencyPicker(): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        if (count($this->productShipmentUrgencies) >= 20) {
            $this->addError('productShipmentUrgencies', 'A product can have up to 20 shipping urgencies.');
            return;
        }

        $this->productShipmentUrgencyPickerSelection = [];
        $this->productShipmentUrgencyPickerOpen = true;
        $this->resetValidation('productShipmentUrgencies');
    }

    public function closeProductShipmentUrgencyPicker(): void
    {
        $this->productShipmentUrgencyPickerOpen = false;
        $this->productShipmentUrgencyPickerSelection = [];
    }

    public function toggleProductShipmentUrgencyPickerSelection(int $urgencyId): void
    {
        abort_unless($this->group === 'product' && $this->showModal && $this->productShipmentUrgencyPickerOpen, 404);

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $exists = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('shipment_urgency')
            ->whereKey($urgencyId)
            ->where('status', 'active')
            ->exists();
        if (! $exists) return;

        $alreadyAdded = collect($this->productShipmentUrgencies)
            ->pluck('shipment_urgency_id')
            ->map(fn ($value) => (int) $value)
            ->contains($urgencyId);
        if ($alreadyAdded) return;

        $selection = collect($this->productShipmentUrgencyPickerSelection)
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique()
            ->values();

        if ($selection->contains($urgencyId)) {
            $this->productShipmentUrgencyPickerSelection = $selection
                ->reject(fn ($value) => $value === $urgencyId)
                ->values()
                ->all();
            return;
        }

        if (count($this->productShipmentUrgencies) + $selection->count() >= 20) {
            $this->addError('productShipmentUrgencies', 'A product can have up to 20 shipping urgencies.');
            return;
        }

        $this->productShipmentUrgencyPickerSelection = $selection->push($urgencyId)->values()->all();
        $this->resetValidation('productShipmentUrgencies');
    }

    public function confirmProductShipmentUrgencies(): void
    {
        abort_unless($this->group === 'product' && $this->showModal && $this->productShipmentUrgencyPickerOpen, 404);

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $existingIds = collect($this->productShipmentUrgencies)
            ->pluck('shipment_urgency_id')
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique();
        $requestedIds = collect($this->productShipmentUrgencyPickerSelection)
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique()
            ->reject(fn ($id) => $existingIds->contains($id));

        $validIds = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('shipment_urgency')
            ->where('status', 'active')
            ->whereIn('id', $requestedIds->all())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($value) => (int) $value);

        foreach ($validIds as $urgencyId) {
            if (count($this->productShipmentUrgencies) >= 20) break;
            $this->productShipmentUrgencies[] = [
                'key' => (string) Str::uuid(),
                'shipment_urgency_id' => (string) $urgencyId,
                'extra_charge' => '',
            ];
        }

        $this->closeProductShipmentUrgencyPicker();
        $this->resetValidation('productShipmentUrgencies');
    }

    public function removeProductShipmentUrgency(int $index): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 404);
        if (! array_key_exists($index, $this->productShipmentUrgencies)) return;

        unset($this->productShipmentUrgencies[$index]);
        $this->productShipmentUrgencies = array_values($this->productShipmentUrgencies);
        $this->resetValidation('productShipmentUrgencies');
    }

    public function updatedProductCustomerPriceTable(): void
    {
        if ($this->group !== 'product') return;

        $this->resetValidation('productCustomerPriceTable');
        $this->productCustomerPricePreview = app(ProductPriceTableParser::class)
            ->parseTable($this->productCustomerPriceTable)['price_breakpoints'];
    }

    public function updatedProductPriceTable(): void
    {
        if ($this->group !== 'product') {
            return;
        }

        abort_unless(app(\App\Services\Orders\SupplierCostVisibility::class)->allowed(), 403);
        $this->resetValidation('productPriceTable');
        $parsedPriceTable = app(ProductPriceTableParser::class)->parseTable($this->productPriceTable);
        $this->productPricePreview = $parsedPriceTable['price_breakpoints'];
        $this->productRemoteSurchargePreview = $parsedPriceTable['remote_surcharge_breakpoints'];
    }
}
