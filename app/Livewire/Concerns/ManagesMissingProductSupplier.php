<?php

namespace App\Livewire\Concerns;

use App\Models\MasterRecord;
use App\Services\Catalog\ProductSupplierResolutionService;
use App\Services\MasterDataService;
use Illuminate\Validation\Rule;

/**
 * Reusable Livewire state + orchestration for the "Supplier not linked" flow.
 *
 * Host screens only provide context authorization and the small completion
 * callback that applies the resolved supplier to their own draft/detail state.
 */
trait ManagesMissingProductSupplier
{
    public bool $showMissingProductSupplierModal = false;
    public string $missingProductSupplierName = '';
    public string $missingProductSupplierChoice = 'create';
    public ?int $missingProductExistingSupplierId = null;
    public string $missingProductExistingSupplierLabel = '';
    public string $missingProductNewSupplierName = '';
    public string $missingProductNewSupplierEmail = '';
    public ?int $pendingMissingSupplierProductId = null;
    public ?int $pendingMissingSupplierRowIndex = null;
    public string $missingProductSupplierContext = '';
    public bool $missingProductSupplierAllowSkip = true;
    public string $missingProductSupplierRecordLabel = 'Order';
    public string $missingProductSupplierSubmitMode = 'add';
    public string $missingProductSupplierSelectorContext = 'create-job';

    abstract protected function authorizeMissingProductSupplierContext(string $context): void;

    abstract protected function completeMissingProductSupplierContext(
        MasterRecord $product,
        ?int $supplierId,
        bool $skipped,
        ?int $rowIndex,
        string $context,
    ): void;

    /** Guard the screen-specific pending target before any Product Master mutation. */
    abstract protected function assertMissingProductSupplierTargetCurrent(
        MasterRecord $product,
        ?int $rowIndex,
        string $context,
    ): void;

    protected function openMissingProductSupplierModalFor(
        MasterRecord $product,
        ?int $rowIndex = null,
        string $context = 'create_order',
        bool $allowSkip = true,
        string $recordLabel = 'Order',
        string $submitMode = 'add',
    ): void {
        $this->resetMissingProductSupplierResolutionFields();
        $this->missingProductSupplierName = (string) $product->name;
        $this->pendingMissingSupplierProductId = (int) $product->id;
        $this->pendingMissingSupplierRowIndex = $rowIndex;
        $this->missingProductSupplierContext = $context;
        $this->missingProductSupplierAllowSkip = $allowSkip;
        $this->missingProductSupplierRecordLabel = $recordLabel;
        $this->missingProductSupplierSubmitMode = in_array($submitMode, ['add', 'continue'], true) ? $submitMode : 'add';
        $this->missingProductSupplierSelectorContext = match ($context) {
            'order_detail' => 'job-detail',
            'create_inquiry' => 'create-inquiry',
            'inquiry_detail' => 'inquiry-detail',
            default => 'create-job',
        };
        $this->showMissingProductSupplierModal = true;
    }

    public function closeMissingProductSupplierModal(): void
    {
        $this->showMissingProductSupplierModal = false;
        $this->missingProductSupplierName = '';
        $this->pendingMissingSupplierProductId = null;
        $this->pendingMissingSupplierRowIndex = null;
        $this->missingProductSupplierContext = '';
        $this->missingProductSupplierAllowSkip = true;
        $this->missingProductSupplierRecordLabel = 'Order';
        $this->missingProductSupplierSubmitMode = 'add';
        $this->missingProductSupplierSelectorContext = 'create-job';
        $this->resetMissingProductSupplierResolutionFields();
    }

    public function selectMissingProductSupplier(string $property, mixed $supplierId): array
    {
        $context = $this->activeMissingProductSupplierContext();
        $this->authorizeMissingProductSupplierContext($context);
        abort_unless(auth()->user()->canModule('catalog_products', 'edit'), 403);
        abort_unless($property === 'missingProductExistingSupplierId', 422, 'Invalid supplier target.');

        $supplierId = filled($supplierId) ? (int) $supplierId : 0;
        abort_unless($supplierId > 0, 422, 'Select a supplier.');

        $supplier = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId, ['id', 'name', 'code', 'metadata', 'type', 'status']);

        $this->missingProductExistingSupplierId = (int) $supplier->id;
        $this->missingProductExistingSupplierLabel = $supplier->supplierShortCode();
        $this->missingProductSupplierChoice = 'existing';
        $this->resetValidation('missingProductExistingSupplierId');

        return ['ok' => true, 'value' => (string) $supplier->id, 'label' => $supplier->supplierShortCode()];
    }

    public function resolveMissingProductSupplier(): void
    {
        $context = $this->activeMissingProductSupplierContext();
        $this->authorizeMissingProductSupplierContext($context);

        $choices = $this->missingProductSupplierAllowSkip
            ? ['existing', 'create', 'skip']
            : ['existing', 'create'];

        $choice = $this->validate([
            'missingProductSupplierChoice' => ['required', Rule::in($choices)],
        ])['missingProductSupplierChoice'];

        $product = $this->pendingMissingSupplierProduct();
        $this->assertMissingProductSupplierTargetCurrent(
            $product,
            $this->pendingMissingSupplierRowIndex,
            $context,
        );
        $catalog = app(\App\Services\ProductCatalogService::class);

        // Another request may have resolved Product Master while this modal was
        // open. Use that canonical supplier without creating or overwriting it.
        if ($currentSupplier = $catalog->supplierForProduct($product)) {
            $this->finishMissingProductSupplierResolution($product, (int) $currentSupplier->id, false, $context);
            return;
        }

        if ($choice === 'skip') {
            abort_unless($this->missingProductSupplierAllowSkip, 422, 'A supplier is required for this product.');
            $this->finishMissingProductSupplierResolution($product, null, true, $context);
            return;
        }

        abort_unless(auth()->user()->canModule('catalog_products', 'edit'), 403);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $resolver = app(ProductSupplierResolutionService::class);

        if ($choice === 'existing') {
            $data = $this->validate([
                'missingProductExistingSupplierId' => [
                    'required',
                    'integer',
                    Rule::exists('master_records', 'id')->where(fn ($query) => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('type', 'supplier')
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
                ],
            ], [
                'missingProductExistingSupplierId.required' => 'Select a supplier to link.',
            ]);

            $supplier = $resolver->linkExisting((int) $product->id, (int) $data['missingProductExistingSupplierId']);
            $this->finishMissingProductSupplierResolution($product, (int) $supplier->id, false, $context);
            return;
        }

        abort_unless(auth()->user()->canModule('suppliers', 'create'), 403);
        $data = $this->validate([
            'missingProductNewSupplierName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('master_records', 'name')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'supplier')
                    ->whereNull('deleted_at')),
            ],
            'missingProductNewSupplierEmail' => ['nullable', 'email:rfc', 'max:255'],
        ], [
            'missingProductNewSupplierName.required' => 'Supplier name is required.',
            'missingProductNewSupplierName.unique' => 'A supplier with this name already exists. Link the existing supplier instead.',
            'missingProductNewSupplierEmail.email' => 'Enter a valid email address.',
        ]);

        $supplier = $resolver->createAndLink(
            (int) $product->id,
            (string) $data['missingProductNewSupplierName'],
            (string) ($data['missingProductNewSupplierEmail'] ?? ''),
        );

        $this->finishMissingProductSupplierResolution($product, (int) $supplier->id, false, $context);
    }

    // Backward-compatible entry points retained for Create Order bindings and
    // any saved Livewire snapshots from the previous implementation.
    public function selectMissingCreateOrderSupplier(string $property, mixed $supplierId): array
    {
        return $this->selectMissingProductSupplier($property, $supplierId);
    }

    public function resolveMissingCreateOrderProductSupplier(): void
    {
        $this->resolveMissingProductSupplier();
    }

    public function skipMissingCreateOrderProductSupplier(): void
    {
        abort_unless($this->missingProductSupplierAllowSkip, 422, 'A supplier is required for this product.');
        $this->missingProductSupplierChoice = 'skip';
        $this->resolveMissingProductSupplier();
    }

    private function finishMissingProductSupplierResolution(
        MasterRecord $product,
        ?int $supplierId,
        bool $skipped,
        string $context,
    ): void {
        $rowIndex = $this->pendingMissingSupplierRowIndex;
        $this->completeMissingProductSupplierContext($product, $supplierId, $skipped, $rowIndex, $context);
        $this->closeMissingProductSupplierModal();
    }

    private function pendingMissingSupplierProduct(): MasterRecord
    {
        $productId = (int) ($this->pendingMissingSupplierProductId ?? 0);
        abort_unless($productId > 0, 422, 'No pending product is available to add.');

        return app(\App\Services\ProductCatalogService::class)->findActiveProductOrFail($productId);
    }

    private function activeMissingProductSupplierContext(): string
    {
        abort_unless($this->showMissingProductSupplierModal, 422, 'The supplier resolution dialog is not open.');
        $context = trim($this->missingProductSupplierContext);
        abort_unless($context !== '', 422, 'The supplier resolution context is missing.');

        return $context;
    }

    private function resetMissingProductSupplierResolutionFields(): void
    {
        $this->missingProductSupplierChoice = 'create';
        $this->missingProductExistingSupplierId = null;
        $this->missingProductExistingSupplierLabel = '';
        $this->missingProductNewSupplierName = '';
        $this->missingProductNewSupplierEmail = '';
        $this->resetValidation([
            'missingProductSupplierChoice',
            'missingProductExistingSupplierId',
            'missingProductNewSupplierName',
            'missingProductNewSupplierEmail',
        ]);
    }
}
