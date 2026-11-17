<?php

namespace App\Livewire\MasterData\Concerns;

use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Services\SupplierBridge\SupplierCurrencyPriceTables;
use App\Services\SupplierBridge\SupplierPriceMatrix;
use App\Services\SupplierBridge\SupplierPricing;
use Illuminate\Validation\ValidationException;

/** Isolated editor for additional supplier currencies; never edits the proposal/primary table. */
trait ManagesSupplierCurrencyTables
{
    public bool $editingSupplierCurrencyTable = false;
    public int $currencyTableSupplierId = 0;
    public string $currencyTableCurrency = '';
    public string $currencyTableOriginalCurrency = '';
    public string $currencyTableVersion = '';
    public array $currencyTableTiers = [];

    private function editableCurrencyProduct(int $supplierId): MasterRecord
    {
        abort_unless($this->group === 'product' && (($this->showProductView && $this->viewProductId) || ($this->showModal && $this->editId)), 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit') &&
            app(\App\Services\Orders\SupplierCostVisibility::class)->allowed() &&
            app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
                ->permitsSection(auth()->user(), 'supplier_pricing', 'edit'), 403);
        $product = MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')->findOrFail($this->showProductView ? $this->viewProductId : $this->editId);
        $access = app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class);
        if ($access->isPortalActor(auth()->user())) {
            abort_unless($access->supplierId(auth()->user()) === $supplierId, 403);
        }
        abort_unless(app(SupplierPricing::class)->assigned($product, $supplierId), 404);
        return $product;
    }

    public function openSupplierCurrencyTable(int $supplierId, string $currency = ''): void
    {
        $product = $this->editableCurrencyProduct($supplierId);
        $tables = app(SupplierCurrencyPriceTables::class);
        $found = collect($tables->forProduct($product, [$supplierId]))->firstWhere('currency', $currency);
        abort_if($currency !== '' && $found === null, 404);
        $this->currencyTableSupplierId = $supplierId;
        $this->currencyTableOriginalCurrency = $currency;
        $this->currencyTableCurrency = $currency;
        $this->currencyTableVersion = $found['version'] ?? $tables->version([], []);
        $this->currencyTableTiers = app(SupplierPriceMatrix::class)
            ->editableTiers($found['rows'] ?? [], $found['charges'] ?? []);
        $this->editingSupplierCurrencyTable = true;
        $this->productDetailSectionsReady['pricing'] = true;
        $this->resetValidation('currencyTableTiers');
        $this->resetValidation('currencyTableCurrency');
    }

    public function addSupplierCurrencyTier(): void
    {
        $this->editableCurrencyProduct($this->currencyTableSupplierId);
        abort_unless($this->editingSupplierCurrencyTable, 404);
        if (count($this->currencyTableTiers) < 100) {
            $this->currencyTableTiers[] = app(SupplierPriceMatrix::class)->blankTier();
        }
    }

    public function removeSupplierCurrencyTier(int $index): void
    {
        $this->editableCurrencyProduct($this->currencyTableSupplierId);
        abort_unless($this->editingSupplierCurrencyTable, 404);
        if (count($this->currencyTableTiers) > 1) array_splice($this->currencyTableTiers, $index, 1);
    }

    public function closeSupplierCurrencyTable(): void
    {
        $this->editingSupplierCurrencyTable = false;
        $this->currencyTableTiers = [];
    }

    public function saveSupplierCurrencyTable(): void
    {
        $product = $this->editableCurrencyProduct($this->currencyTableSupplierId);
        abort_unless($this->editingSupplierCurrencyTable, 404);
        $this->validate([
            'currencyTableCurrency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'currencyTableVersion' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'currencyTableTiers' => ['required', 'array', 'max:100'],
        ]);
        abort_if($this->currencyTableOriginalCurrency !== '' &&
            $this->currencyTableOriginalCurrency !== $this->currencyTableCurrency, 422,
            'Currency cannot be changed on an existing table. Remove it and create a new table.');
        try {
            app(SupplierCurrencyPriceTables::class)->save($product, $this->currencyTableSupplierId,
                $this->currencyTableCurrency, $this->currencyTableTiers, $this->currencyTableVersion);
        } catch (ValidationException $exception) {
            // The shared supplier matrix reports legacy field names. Point the
            // same validation feedback at this isolated currency-table editor.
            $messages = $exception->errors();
            throw ValidationException::withMessages([
                'currencyTableTiers' => $messages['productSupplierPrices'][0]
                    ?? $messages['tiers'][0] ?? $messages['prices'][0]
                    ?? $messages['currency'][0] ?? $exception->getMessage(),
            ]);
        }
        $this->refreshCurrencyTableCards($product);
        $this->closeSupplierCurrencyTable();
        session()->flash('success', 'Additional supplier currency price table saved.');
    }

    private function refreshCurrencyTableCards(MasterRecord $product): void
    {
        if (! $this->showModal) return;
        $tables = app(SupplierCurrencyPriceTables::class)->forProduct($product, [$this->currencyTableSupplierId]);
        foreach ($this->productSupplierPrices as &$entry) {
            if ((int) ($entry['supplier_id'] ?? 0) === $this->currencyTableSupplierId) {
                $entry['additional_tables'] = $tables;
            }
        }
        unset($entry);
    }

    public function deleteSupplierCurrencyTable(): void
    {
        $product = $this->editableCurrencyProduct($this->currencyTableSupplierId);
        abort_unless($this->editingSupplierCurrencyTable && $this->currencyTableOriginalCurrency !== '', 404);
        app(SupplierCurrencyPriceTables::class)->save($product, $this->currencyTableSupplierId,
            $this->currencyTableOriginalCurrency, [], $this->currencyTableVersion, true);
        $this->refreshCurrencyTableCards($product);
        $this->closeSupplierCurrencyTable();
        session()->flash('success', 'Additional currency table removed.');
    }
}
