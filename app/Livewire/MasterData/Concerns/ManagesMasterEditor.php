<?php

namespace App\Livewire\MasterData\Concerns;

use App\Models\Client;
use App\Models\MasterRecord;
use App\Support\Filters\ProductClientOptions;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use App\Services\ProductOptionImageService;
use App\Services\ProductPriceTableParser;
use App\Services\ClientPriceMatrix;
use App\Services\ProductCategoryDeletionService;
use App\Support\MasterColor;
use App\Support\AttachmentUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesMasterEditor
{
    /** Keep one editable price card per tagged supplier, without overwriting cards being edited. */
    public function syncProductSupplierPriceRows(?MasterRecord $product = null): void
    {
        if ($this->group !== 'product' || ! $this->showModal) return;
        if (! app(\App\Services\Orders\SupplierCostVisibility::class)->allowed()) {
            $this->productSupplierPrices = [];
            return;
        }

        $ids = collect($this->productSupplierIds)
            ->push($this->productSupplierId)
            ->map(fn ($id) => (int) $id)->filter()->unique()->take(100)->values();
        $access = app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class);
        if ($access->isPortalActor(auth()->user())) {
            $ids = $ids->filter(fn ($id) => $id === $access->supplierId(auth()->user()))->values();
        }
        $existing = collect($this->productSupplierPrices)->keyBy('supplier_id');
        $missing = $ids->reject(fn ($id) => $existing->has($id));
        $labels = $missing->isEmpty() ? collect() : MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())->ofType('supplier')
            ->whereIn('id', $missing->all())->get(['id', 'name', 'code', 'metadata'])->keyBy('id');
        $overrides = $this->editId && $missing->isNotEmpty()
            ? DB::table('supplier_product_prices')->where('workspace_id', app(MasterDataService::class)->workspaceId())
                ->where('product_id', $this->editId)->whereIn('supplier_id', $missing->all())
                ->get(['supplier_id', 'price_breakpoints', 'additional_charges'])->keyBy('supplier_id')
            : collect();
        $pricing = app(\App\Services\SupplierBridge\SupplierPricing::class);
        $matrix = app(\App\Services\SupplierBridge\SupplierPriceMatrix::class);
        // The optimistic-lock version must use the exact same fallback as
        // SupplierPricing::rows(), which is what the approval API compares.
        // A draft preview can differ from saved metadata, causing false conflicts.
        $fallback = $this->productPricePreview;
        if ($this->editId && $missing->isNotEmpty()) {
            $savedProduct = $product ?? MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('product')->findOrFail($this->editId);
            $fallback = $savedProduct->supplierPriceBreakpoints();
        }
        $extraTables = collect();
        if ($this->editId && $missing->isNotEmpty()) {
            $sourceProduct = $product ?? ($savedProduct ?? null) ?? MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('product')->findOrFail($this->editId);
            $extraTables = collect(app(\App\Services\SupplierBridge\SupplierCurrencyPriceTables::class)
                ->forProduct($sourceProduct, $missing->all()))->groupBy('supplier_id');
        }
        $updated = [];
        foreach ($ids as $id) {
            if ($existing->has($id)) {
                $updated[] = $existing->get($id);
                continue;
            }
            $supplier = $labels->get($id);
            if (! $supplier) continue;
            $override = $overrides->get($id);
            $rows = $pricing->normalize($override !== null
                ? (json_decode((string) $override->price_breakpoints, true) ?: [])
                : $fallback);
            $charges = $matrix->normalizeCharges(json_decode((string) ($override->additional_charges ?? ''), true) ?: []);
            $table = $rows === [] ? '' : "Quantity\t".collect($rows)->pluck('quantity')->implode("\t")
                ."\nProduct price\t".collect($rows)->pluck('price')->implode("\t");
            $tiers = $matrix->editableTiers($rows, $charges);
            foreach ($tiers as &$tier) $tier['key'] = (string) Str::uuid();
            unset($tier);
            $updated[] = [
                'currency' => ($product ?? ($savedProduct ?? null))?->supplierPriceCurrency((int) $id) ?? 'USD',
                'key' => 'supplier-'.$id,
                'supplier_id' => (string) $id,
                'supplier_name' => $supplier->name,
                'additional_tables' => $extraTables->get((int)$id, collect())->all(),
                'table' => $table,
                'original_table' => $table,
                'original_version' => $pricing->version($rows),
                'original_charges_version' => $matrix->chargesVersion($charges),
                'tiers' => $tiers,
                'preview' => $rows,
            ];
        }
        $this->productSupplierPrices = $updated;
    }

    /** Refresh only supplier price snapshots after a real concurrent change.
     * Preserve other unsaved product fields and client price edits.
     */
    public function refreshProductSupplierPrices(): void
    {
        if ($this->group !== 'product' || ! $this->showModal || ! $this->editId) return;
        abort_unless(app(\App\Services\Orders\SupplierCostVisibility::class)->allowed(), 403);

        $product = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')->findOrFail($this->editId);
        $this->productSupplierPrices = [];
        $this->syncProductSupplierPriceRows($product);
        $this->resetValidation('productSupplierPrices');
        foreach (array_keys($this->productSupplierPrices) as $index) {
            $this->resetValidation('productSupplierPrices.'.$index.'.table');
        }
    }

    public function updatedProductSupplierIds(): void { $this->syncProductSupplierPriceRows(); }
    public function updatedProductSupplierId(): void { $this->syncProductSupplierPriceRows(); }

    public function addSupplierPriceTier(int $index): void
    {
        abort_unless($this->group === 'product' && $this->showModal
            && app(\App\Services\Orders\SupplierCostVisibility::class)->allowed(), 403);
        abort_unless(app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
            ->permitsSection(auth()->user(), 'supplier_pricing', 'edit'), 403);
        if (! isset($this->productSupplierPrices[$index])) return;
        if (count($this->productSupplierPrices[$index]['tiers'] ?? []) >= 100) return;
        $tier = app(\App\Services\SupplierBridge\SupplierPriceMatrix::class)->blankTier();
        $tier['key'] = (string) Str::uuid();
        $this->productSupplierPrices[$index]['tiers'][] = $tier;
    }

    public function removeSupplierPriceTier(int $index, int $tierIndex): void
    {
        abort_unless($this->group === 'product' && $this->showModal
            && app(\App\Services\Orders\SupplierCostVisibility::class)->allowed(), 403);
        abort_unless(app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
            ->permitsSection(auth()->user(), 'supplier_pricing', 'edit'), 403);
        if (! isset($this->productSupplierPrices[$index]['tiers'][$tierIndex])) return;
        array_splice($this->productSupplierPrices[$index]['tiers'], $tierIndex, 1);
        if ($this->productSupplierPrices[$index]['tiers'] === []) {
            $tier = app(\App\Services\SupplierBridge\SupplierPriceMatrix::class)->blankTier();
            $tier['key'] = (string) Str::uuid();
            $this->productSupplierPrices[$index]['tiers'][] = $tier;
        }
        $this->resetValidation('productSupplierPrices');
    }

    /** Keep client grids editable without changing the existing stored price-table schema. */
    private function editableClientPriceTiers(array $breakpoints): array
    {
        $tiers = app(ClientPriceMatrix::class)->editableTiers($breakpoints);
        foreach ($tiers as &$tier) $tier['key'] = (string) Str::uuid();
        unset($tier);
        return $tiers;
    }

    public function addCustomerPriceTier(): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 403);
        abort_unless(app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
            ->permitsSection(auth()->user(), 'client_pricing', 'edit'), 403);
        if (count($this->productCustomerPriceTiers) >= 100) return;
        $this->productCustomerPriceTiers[] = array_merge(
            app(ClientPriceMatrix::class)->blankTier(), ['key' => (string) Str::uuid()]);
    }

    public function removeCustomerPriceTier(int $tierIndex): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 403);
        abort_unless(app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
            ->permitsSection(auth()->user(), 'client_pricing', 'edit'), 403);
        if (! isset($this->productCustomerPriceTiers[$tierIndex])) return;
        array_splice($this->productCustomerPriceTiers, $tierIndex, 1);
        if ($this->productCustomerPriceTiers === []) {
            $this->productCustomerPriceTiers = $this->editableClientPriceTiers([]);
        }
        $this->resetValidation('productCustomerPriceTiers');
    }

    public function addClientPriceTier(int $index): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 403);
        abort_unless(app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
            ->permitsSection(auth()->user(), 'client_pricing', 'edit'), 403);
        if (! isset($this->productClientPrices[$index])) return;
        if (count($this->productClientPrices[$index]['tiers'] ?? []) >= 100) return;
        $this->productClientPrices[$index]['tiers'][] = array_merge(
            app(ClientPriceMatrix::class)->blankTier(), ['key' => (string) Str::uuid()]);
    }

    public function removeClientPriceTier(int $index, int $tierIndex): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 403);
        abort_unless(app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)
            ->permitsSection(auth()->user(), 'client_pricing', 'edit'), 403);
        if (! isset($this->productClientPrices[$index]['tiers'][$tierIndex])) return;
        array_splice($this->productClientPrices[$index]['tiers'], $tierIndex, 1);
        if ($this->productClientPrices[$index]['tiers'] === []) {
            $this->productClientPrices[$index]['tiers'] = $this->editableClientPriceTiers([]);
        }
        $this->resetValidation('productClientPrices.'.$index.'.tiers');
    }

    /** Tagging clients adds their price editors, preserving existing saved prices and unsaved text. */
    public function syncProductClientPriceRowsForSelectedClients(): void
    {
        if ($this->group !== 'product' || ! $this->showModal || $this->productClientAvailabilityMode !== 'specific') return;

        // Keep historical price records for untagged clients untouched; only
        // tagged clients are rendered below. A change in product availability
        // must not silently delete a previously saved client price table.
        $existing = collect($this->productClientPrices)
            ->pluck('client_id')->map(fn ($id) => (int) $id)->all();
        foreach (collect($this->productClientIds)->map(fn ($id) => (int) $id)
            ->filter()->unique()->take(100) as $id) {
            if (! in_array($id, $existing, true)) {
                $this->productClientPrices[] = [
                    'key' => 'client-'.$id, 'client_id' => (string) $id,
                    'table' => '', 'preview' => [], 'tiers' => $this->editableClientPriceTiers([]),
                    'currency' => $this->productCustomerPriceCurrency,
                ];
                $existing[] = $id;
            }
        }
    }

    public function updatedProductClientIds(): void { $this->syncProductClientPriceRowsForSelectedClients(); }

    public function updatedProductClientPrices(mixed $value, string $key): void
    {
        if ($this->group !== 'product' || !preg_match('/^(\d+)\.(table|client_id)$/', $key, $match)) return;
        $index = (int) $match[1];
        if (!isset($this->productClientPrices[$index])) return;
        $this->resetValidation('productClientPrices.'.$index);
        if ($match[2] === 'table') {
            $this->productClientPrices[$index]['preview'] = app(ProductPriceTableParser::class)
                ->parseTable((string) $this->productClientPrices[$index]['table'])['price_breakpoints'];
        }
    }

    public function open(?int $id = null): void
    {
        $action = $id ? 'edit' : 'create';
        $this->authorizeGroupAction($action);
        $service = app(MasterDataService::class);
        if ($this->group === 'product' && $id
            && ! app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)->isPortalActor(auth()->user())) {
            app(\App\Services\ProductTaxonomyService::class)->synchronizeLegacyTaxonomy();
        }
        // Product create/edit is a full-page editor. Do not hydrate the product
        // list behind it; direct ?create=1 navigation should pay only for the
        // form and the progressive sections the user reaches. Compact generic
        // Master Data editors still keep their list available behind the modal.
        if ($this->group !== 'product') {
            $this->recordsReady = true;
        }
        $this->closeSupplierCurrencyTable();
        $this->showModal = true;
        $this->editId = $id;
        // Existing products need their stored taxonomy/urgency values immediately.
        // New products hydrate these below-the-fold reference datasets only when
        // their sections approach the viewport.
        $this->productTaxonomyReady = (bool) $id;
        $this->productShipmentOptionsReady = (bool) $id;
        $this->productImage = null;
        $this->existingProductImageUrl = null;
        $this->removeProductImage = false;
        $this->productCategorySearch = '';
        $this->newProductCategoryName = '';
        $this->productSupplierId = null;
        $this->productSupplierIds = [];
        $this->productSupplierPrices = [];
        $this->productCertificateUpload = null;
        $this->productTemplateUpload = null;
        $this->productOptions = [];
        $this->productOptionUploads = [];
        $this->productShipmentUrgencies = [];
        $this->productShipmentUrgencyPickerOpen = false;
        $this->productShipmentUrgencyPickerSelection = [];
        $this->removeProductCertificate = false;
        $this->removeProductTemplate = false;
        $this->resetCategoryCreatorState();
        $this->workCalendarDayFrom = 'monday';
        $this->workCalendarDayTo = 'friday';
        $this->workCalendarTimeFrom = '09:00';
        $this->workCalendarTimeTo = '18:00';
        $this->remoteAreaPostalCode = '';
        $this->remoteAreaCarrier = 'UPS';
        $this->remoteAreaCountry = '';
        $this->remoteAreaIataCode = '';
        $this->remoteAreaPostalCodeFrom = '';
        $this->remoteAreaPostalCodeTo = '';
        $this->remoteAreaCity = '';
        $this->remoteAreaOriginSurcharge = 'No';
        $this->remoteAreaDestinationSurcharge = 'No';
        $this->remoteAreaExtraCharge = '';
        $this->resetValidation();

        if ($id) {
            $r = MasterRecord::where('workspace_id', $service->workspaceId())->findOrFail($id);
            abort_unless($r->type === $this->group, 404);
            $this->code = $r->code;
            $this->name = $r->name;
            $this->description = (string) $r->description;
            $this->productReferenceCode = $r->productReferenceCode();
            $this->productSupplierId = $r->productSupplierId();
            if ($this->group === 'product') {
                $linkedIds = collect($r->productSupplierIds());
                if (Schema::hasTable('product_supplier_links')) {
                    $linkedIds = $linkedIds->merge(DB::table('product_supplier_links')
                        ->where('workspace_id', $service->workspaceId())
                        ->where('product_id', $r->id)
                        ->pluck('supplier_id'));
                }
                $this->productSupplierIds = $linkedIds->map(fn ($id) => (int) $id)
                    ->filter()->unique()->values()->all();
            }
            $this->productFormMainCategory = $r->productMainCategory();
            $this->productSize = $r->productSize();
            $canSeeSupplierCosts = app(\App\Services\Orders\SupplierCostVisibility::class)->allowed();
            $portalAccess = app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class);
            $canSeeClientPrices = $portalAccess->permitsSection(auth()->user(), 'client_pricing', 'view');
            $this->productPriceTable = $canSeeSupplierCosts ? trim((string) data_get($r->metadata, 'price_table_raw')) : '';
            $this->productCustomerPriceTable = $canSeeClientPrices ? trim((string) data_get($r->metadata, 'customer_price_table_raw')) : '';
            $this->productCustomerPriceCurrency = $r->clientPriceCurrency();
            $this->productCustomerPricePreview = $canSeeClientPrices ? $r->customerPriceBreakpoints() : [];
            $this->productCustomerPriceTiers = $this->editableClientPriceTiers($this->productCustomerPricePreview);
            $this->productClientPrices = $this->group === 'product' && $canSeeClientPrices
                ? $r->clientPrices()->orderBy('client_id')->get(['client_id', 'price_table_raw', 'price_breakpoints'])
                    ->map(function ($row) use ($r) {
                        $raw = trim((string) $row->price_table_raw);
                        $breakpoints = (array) $row->price_breakpoints;
                        if ($breakpoints === [] && $raw !== '') {
                            $breakpoints = app(ProductPriceTableParser::class)->parseTable($raw)['price_breakpoints'];
                        }
                        return [
                            'key' => 'client-'.$row->client_id,
                            'client_id' => (string) $row->client_id,
                            'currency' => $r->clientPriceCurrency((int) $row->client_id),
                            'table' => $raw !== '' ? $raw : app(ClientPriceMatrix::class)->toLegacyTable($breakpoints),
                            'preview' => $breakpoints,
                            'tiers' => $this->editableClientPriceTiers($breakpoints),
                        ];
                    })->all()
                : [];
            if ($this->productCustomerPriceTable === '' && $this->productCustomerPricePreview !== []) {
                $quantities = collect($this->productCustomerPricePreview)->pluck('quantity')->implode("\t");
                $prices = collect($this->productCustomerPricePreview)->pluck('price')->implode("\t");
                $this->productCustomerPriceTable = "Quantity\t".$quantities."\nProduct price\t".$prices;
            }
            $storedPriceBreakpoints = collect($canSeeSupplierCosts ? (array) data_get($r->metadata, 'price_breakpoints', []) : [])
                ->map(fn ($row) => [
                    'quantity' => (int) data_get($row, 'quantity', 0),
                    'price' => (float) data_get($row, 'price', 0),
                ])
                ->filter(fn ($row) => $row['quantity'] > 0 && $row['price'] >= 0)
                ->sortBy('quantity')->values()->all();
            $storedRemoteSurchargeBreakpoints = collect((array) data_get($r->metadata, 'remote_surcharge_breakpoints', []))
                ->map(fn ($row) => [
                    'quantity' => (int) data_get($row, 'quantity', 0),
                    'price' => (float) data_get($row, 'price', 0),
                ])
                ->filter(fn ($row) => $row['quantity'] > 0 && $row['price'] >= 0)
                ->sortBy('quantity')->values()->all();

            if ($this->productPriceTable !== '') {
                $parsedPriceTable = app(ProductPriceTableParser::class)->parseTable($this->productPriceTable);
                $this->productPricePreview = $parsedPriceTable['price_breakpoints'];
                $this->productRemoteSurchargePreview = $parsedPriceTable['remote_surcharge_breakpoints'];
            } else {
                $this->productPricePreview = $storedPriceBreakpoints;
                $this->productRemoteSurchargePreview = $storedRemoteSurchargeBreakpoints;
            }

            if ($canSeeSupplierCosts && $this->productPriceTable === '' && $this->productPricePreview !== []) {
                $quantities = collect($this->productPricePreview)->pluck('quantity')->implode("	");
                $prices = collect($this->productPricePreview)->pluck('price')->implode("	");
                $this->productPriceTable = "Quantity	".$quantities."
Product price	".$prices;
                if ($this->productRemoteSurchargePreview !== []) {
                    $remotePrices = collect($this->productRemoteSurchargePreview)->keyBy('quantity');
                    $remoteRow = collect($this->productPricePreview)
                        ->map(fn ($row) => data_get($remotePrices->get($row['quantity']), 'price', ''))
                        ->implode("	");
                    $this->productPriceTable .= "
Remote Area charge	".$remoteRow;
                }
            }
            $remoteRows = $r->productRemoteSurchargeBreakpoints();
            $this->productRemoteSurchargeTable = $remoteRows !== []
                ? "Quantity\t".collect($remoteRows)->pluck('quantity')->implode("\t")
                    ."\nProduct price\t".collect($remoteRows)->pluck('price')->implode("\t")
                : '';
            // The supplier table (and its legacy fallback) must not enter the
            // browser's Livewire snapshot unless the role can view supplier costs.
            if (! $canSeeSupplierCosts) $this->productPricePreview = [];
            $this->productOptions = $r->productOptions();
            $this->productOptionUploads = [];
            $this->productShipmentUrgencies = collect($r->productShipmentUrgencyOptions())
                ->map(fn (array $option) => [
                    'key' => trim((string) ($option['key'] ?? '')) ?: (string) Str::uuid(),
                    'shipment_urgency_id' => (string) ($option['shipment_urgency_id'] ?? ''),
                    'extra_charge' => (float) ($option['extra_charge'] ?? 0) > 0 ? (string) $option['extra_charge'] : '',
                ])->values()->all();
            $this->productSubcategory = trim((string) (data_get($r->metadata, 'sub_category') ?: data_get($r->metadata, 'excel_sub_category')));
            $this->productClientAvailabilityMode = $r->hasSpecificProductAvailability() ? 'specific' : 'all';
            $storedClientIds = collect((array) data_get($r->metadata, 'client_ids', []))->map(fn ($value) => (int) $value)->filter()->values()->all();
            if (!$storedClientIds && $r->hasSpecificProductAvailability()) {
                $labels = collect($r->productAvailabilityLabels())
                    ->map(fn ($value) => mb_strtolower(trim((string) $value)))
                    ->filter()
                    ->unique()
                    ->values();
                $storedClientIds = $labels->isEmpty()
                    ? []
                    : Client::query()
                        ->where('is_active', true)
                        ->where(function ($query) use ($labels): void {
                            foreach ($labels as $label) {
                                $query->orWhereRaw('LOWER(TRIM(name)) = ?', [$label])
                                    ->orWhereRaw('LOWER(TRIM(code)) = ?', [$label]);
                            }
                        })
                        ->pluck('id')
                        ->map(fn ($value) => (int) $value)
                        ->values()
                        ->all();
            }
            $this->productClientIds = $storedClientIds;
            $this->syncProductClientPriceRowsForSelectedClients();
            // A delegated supplier may view/edit only its own price card, never
            // any other supplier's confidential pricing or metadata.
            $access = app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class);
            if ($this->group === 'product' && $access->isPortalActor(auth()->user())) {
                $myId = $access->supplierId(auth()->user());
                $this->productSupplierPrices = [];
                $this->productSupplierIds = [$myId];
                $this->productSupplierId = $myId;
            }
            $this->syncProductSupplierPriceRows($r);
            $this->productTestCertificateNumber = trim((string) data_get($r->metadata, 'test_certificate_number'));
            $this->color = MasterColor::normalize($r->color) ?: MasterColor::defaultFor($this->group, $r->name);
            $this->parentId = in_array($this->group, ['product', 'state'], true) ? $r->parent_id : null;
            if ($this->group === 'product' && $r->parent_id) {
                $this->productCategorySearch = (string) $r->parent?->name;
            }
            $this->status = $r->status;
            $this->sortOrder = (int) $r->sort_order;
            $this->existingProductImageUrl = $r->productImageUrl();
            $metadata = (array) ($r->metadata ?? []);
            if ($this->group === 'task_pack_work_calendar') {
                $this->workCalendarDayFrom = strtolower(trim((string) ($metadata['day_from'] ?? 'monday'))) ?: 'monday';
                $this->workCalendarDayTo = strtolower(trim((string) ($metadata['day_to'] ?? 'friday'))) ?: 'friday';
                $this->workCalendarTimeFrom = trim((string) ($metadata['time_from'] ?? '09:00')) ?: '09:00';
                $this->workCalendarTimeTo = trim((string) ($metadata['time_to'] ?? '18:00')) ?: '18:00';
            }
            if ($this->group === 'remote_area') {
                $this->remoteAreaPostalCode = $r->remoteAreaPostalCode();
                $this->remoteAreaCarrier = $r->remoteAreaCarrier() ?: 'UPS';
                $this->remoteAreaCountry = $r->remoteAreaCountry();
                $this->remoteAreaIataCode = $r->remoteAreaIataCode();
                $this->remoteAreaPostalCodeFrom = $r->remoteAreaPostalCodeFrom();
                $this->remoteAreaPostalCodeTo = $r->remoteAreaPostalCodeTo();
                $this->remoteAreaCity = $r->remoteAreaCity();
                $this->remoteAreaOriginSurcharge = $r->remoteAreaOriginSurcharge() ?: 'No';
                $this->remoteAreaDestinationSurcharge = $r->remoteAreaDestinationSurcharge() ?: 'No';
                $remoteAreaExtraCharge = $r->remoteAreaExtraCharge();
                $this->remoteAreaExtraCharge = $remoteAreaExtraCharge === null
                    ? ''
                    : rtrim(rtrim(number_format($remoteAreaExtraCharge, 2, '.', ''), '0'), '.');
            }
            if ($this->group === 'inquiry_task_status') {
                $this->autoInquiryStatus = (string) ($metadata['auto_inquiry_status'] ?? '__task_status__');
                $this->requiresAttention = filter_var($metadata['requires_attention'] ?? false, FILTER_VALIDATE_BOOL);
            } else {
                $this->autoInquiryStatus = 'To do';
                $this->requiresAttention = false;
            }
            $this->orderTaskFlagId = $this->group === 'order_task_status'
                ? ((int) ($metadata['order_task_flag_id'] ?? 0) ?: null)
                : null;
            $this->orderFlagId = $this->group === 'order_task_flag'
                ? ((int) ($metadata['order_flag_id'] ?? 0) ?: null)
                : null;
            unset($metadata['product_image_path']);
            if ($this->group === 'product') {
                // A generic JSON state property must not leak confidential costs.
                unset($metadata['price_table_raw'], $metadata['price_breakpoints']);
            }
            if ($this->group === 'product') {
                $access = app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class);
                if ($access->isPortalActor(auth()->user())) {
                    // metadataJson is public Livewire state. Never hydrate
                    // restricted price information into the supplier browser.
                    foreach (['price_table_raw', 'price_breakpoints', 'supplier_price_currency',
                        'supplier_price_currencies', 'customer_price_table_raw',
                        'customer_price_breakpoints', 'customer_price_currency',
                        'client_price_currencies', 'product_image_path',
                        'certificate_test_report_path', 'certificate_test_report',
                        'template_doc_path', 'template_doc', 'template_doc_url', 'certificate_test_report_url'] as $sensitive) {
                        unset($metadata[$sensitive]);
                    }
                    if (! $access->can(auth()->user(), 'options', 'view')) {
                        unset($metadata['options'], $metadata['product_options']);
                        $this->productOptions = [];
                    }
                    if (! $access->can(auth()->user(), 'shipment', 'view')) {
                        unset($metadata['remote_surcharge_breakpoints'], $metadata['shipment_urgency_options']);
                        $this->productShipmentUrgencies = [];
                        $this->productRemoteSurchargeTable = '';
                        $this->productRemoteSurchargePreview = [];
                    }
                    if (! $access->can(auth()->user(), 'availability', 'view')) {
                        unset($metadata['client_availability'], $metadata['client_ids'],
                            $metadata['client_availability_labels'], $metadata['client_codes']);
                        $this->productClientIds = [];
                        $this->productClientAvailabilityMode = 'all';
                    }
                    if (! $access->can(auth()->user(), 'documents', 'view')) {
                        unset($metadata['test_certificate_number']);
                        $this->productTestCertificateNumber = '';
                    }
                }
            }
            $this->metadataJson = $metadata ? json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
            return;
        }

        $this->reset(['code', 'name', 'description', 'parentId', 'metadataJson']);
        $this->color = MasterColor::defaultFor($this->group);
        $this->code = $service->nextCode($this->group);
        if ($this->group === 'product') {
            $this->productReferenceCode = '';
            $this->productSupplierId = null;
            $this->productSupplierIds = [];
            $this->productSupplierPrices = [];
            $this->productFormMainCategory = '';
            $this->productSize = '';
            $this->productPriceTable = '';
            $this->productRemoteSurchargeTable = '';
            $this->productCustomerPriceTable = '';
            $this->productCustomerPriceCurrency = 'USD';
            $this->productCustomerPricePreview = [];
            $this->productCustomerPriceTiers = $this->editableClientPriceTiers([]);
            $this->productClientPrices = [];
            $this->productPricePreview = [];
            $this->productRemoteSurchargePreview = [];
            $this->productOptions = [];
            $this->productOptionUploads = [];
            $this->productShipmentUrgencies = [];
            $this->productShipmentUrgencyPickerOpen = false;
            $this->productShipmentUrgencyPickerSelection = [];
            $this->productSubcategory = '';
            $this->productClientAvailabilityMode = 'all';
            $this->productClientIds = [];
            $this->productTestCertificateNumber = '';
        }
        $this->status = 'active';
        $this->remoteAreaPostalCode = '';
        $this->remoteAreaCarrier = 'UPS';
        $this->remoteAreaCountry = '';
        $this->remoteAreaIataCode = '';
        $this->remoteAreaPostalCodeFrom = '';
        $this->remoteAreaPostalCodeTo = '';
        $this->remoteAreaCity = '';
        $this->remoteAreaOriginSurcharge = 'No';
        $this->remoteAreaDestinationSurcharge = 'No';
        $this->remoteAreaExtraCharge = '';
        $this->autoInquiryStatus = 'To do';
        $this->requiresAttention = false;
        $this->orderTaskFlagId = null;
        $this->orderFlagId = null;
        $this->sortOrder = (int) MasterRecord::where('workspace_id', $service->workspaceId())->where('type', $this->group)->max('sort_order') + 1;
    }
    public function loadCreateSection(string $section): void
    {
        abort_unless($this->group === 'product' && $this->showModal, 422);

        if ($section === 'product-taxonomy') {
            if (! $this->productTaxonomyReady) {
                app(\App\Services\ProductTaxonomyService::class)->synchronizeLegacyTaxonomy();
                $this->productTaxonomyReady = true;
            }
            return;
        }

        if ($section === 'product-shipping-urgencies') {
            $this->productShipmentOptionsReady = true;
            return;
        }

        abort(422, 'Unknown Create Product section.');
    }

    public function close(): void
    {
        $this->showModal = false;
        if ($this->group === 'product') {
            $this->recordsReady = true;
        }
        $this->productTaxonomyReady = false;
        $this->productShipmentOptionsReady = false;
        $this->productImage = null;
        $this->existingProductImageUrl = null;
        $this->removeProductImage = false;
        $this->productCategorySearch = '';
        $this->newProductCategoryName = '';
        $this->productCertificateUpload = null;
        $this->productTemplateUpload = null;
        $this->productOptions = [];
        $this->productOptionUploads = [];
        $this->productShipmentUrgencies = [];
        $this->productShipmentUrgencyPickerOpen = false;
        $this->productShipmentUrgencyPickerSelection = [];
        $this->removeProductCertificate = false;
        $this->removeProductTemplate = false;
        $this->resetCategoryCreatorState();
        $this->resetValidation();
    }
    public function save(): void
    {
        // A forged Livewire call cannot submit an editor the supplier may not open.
        if (auth()->user() && app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)->isPortalActor(auth()->user())) {
            $this->authorizeGroupAction($this->editId ? 'edit' : 'create');
        }
        $service = app(MasterDataService::class);
        $portalAccess = app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class);
        $portalActor = $this->group === 'product' && $portalAccess->isPortalActor(auth()->user());
        $canEditClientPrices = ! $portalActor || $portalAccess->can(auth()->user(), 'client_pricing', 'edit');
        $canEditSupplierPricesForPortal = ! $portalActor || $portalAccess->can(auth()->user(), 'supplier_pricing', 'edit');
        if ($portalActor) {
            $supplierId = $portalAccess->supplierId(auth()->user());
            $this->productSupplierPrices = array_values(array_filter($this->productSupplierPrices,
                static fn (array $row): bool => (int) ($row['supplier_id'] ?? 0) === $supplierId));
            // The legacy base table applies to every supplier and may be edited
            // only by FlowTracker staff; the portal uses its own supplier row.
            $this->productPriceTable = '';
            $this->productPricePreview = [];
            if ($this->editId) {
                $original = MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())
                    ->ofType('product')->findOrFail($this->editId);
                $portalAccess->assertProduct(auth()->user(), $original);
                // Portal actors cannot change another supplier's assignment or
                // remove links. Preserve the authoritative saved supplier set.
                $this->productSupplierId = $original->productSupplierId();
                $savedSupplierIds = collect($original->productSupplierIds());
                if (Schema::hasTable('product_supplier_links')) {
                    $savedSupplierIds = $savedSupplierIds->merge(DB::table('product_supplier_links')
                        ->where('workspace_id', $original->workspace_id)
                        ->where('product_id', $original->id)->pluck('supplier_id'));
                }
                $this->productSupplierIds = $savedSupplierIds->map(fn ($id) => (int) $id)
                    ->filter()->unique()->values()->all();
            } else {
                // A supplier-created product is automatically linked only to that supplier.
                $this->productSupplierId = $supplierId;
                $this->productSupplierIds = [$supplierId];
            }
            if (! $canEditSupplierPricesForPortal) {
                $this->productSupplierPrices = [];
                $this->productPriceTable = '';
                $this->productPricePreview = [];
            }
            if (! $canEditClientPrices) {
                $this->productClientPrices = [];
                $this->productCustomerPriceTable = '';
                $this->productCustomerPricePreview = [];
                $this->productCustomerPriceTiers = [['key' => 'blank', 'quantity' => '', 'price' => '']];
            }
            if (! $portalAccess->can(auth()->user(), 'documents', 'edit')) {
                abort_if($this->productImage || $this->productCertificateUpload || $this->productTemplateUpload
                    || $this->removeProductImage || $this->removeProductCertificate || $this->removeProductTemplate
                    || array_filter($this->productOptionUploads), 403);
            }
        }

        $workspaceId = $service->workspaceId();
        $portalOriginal = $portalActor && $this->editId
            ? MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')->findOrFail($this->editId)
            : null;
        if ($portalActor) {
            // Suppress writes to sections not granted by FlowTracker. The
            // authoritative values are copied back immediately before save.
            if (! $portalAccess->can(auth()->user(), 'options', 'edit')) {
                abort_if(array_filter($this->productOptionUploads), 403);
                $this->productOptions = [];
            }
            if (! $portalAccess->can(auth()->user(), 'shipment', 'edit')) {
                $this->productShipmentUrgencies = [];
                $this->productRemoteSurchargeTable = '';
            }
            if (! $portalAccess->can(auth()->user(), 'availability', 'edit')) {
                $this->productClientAvailabilityMode = 'all';
                $this->productClientIds = [];
            }
        }

        // Product display codes are generated from the record id. The underlying
        // master-data code stays a unique internal key; the supplier/reference
        // code is stored separately in metadata.
        if (!$this->editId) {
            $this->code = $service->nextCode($this->group);
        }

        // Remote Area names are internal display labels generated from the UPS
        // location fields, so admins do not have to maintain a duplicate name.
        if ($this->group === 'remote_area') {
            $this->name = $this->remoteAreaGeneratedName();
        }

        $currencyCodes = $this->group === 'product'
            ? $service->active('currency')->map(fn (MasterRecord $row) => $service->currencyValue($row))
                ->filter()->unique()->values()->all()
            : [];
        $currencyRule = Rule::in($currencyCodes);

        $data = $this->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => $this->group === 'phone_country_code'
                ? [
                    'required',
                    'string',
                    'max:12',
                    'regex:/^\+[0-9]{1,4}$/',
                    Rule::unique('master_records', 'name')
                        ->where(fn ($query) => $query
                            ->where('workspace_id', $workspaceId)
                            ->where('type', 'phone_country_code')
                            ->whereNull('deleted_at'))
                        ->ignore($this->editId),
                ]
                : ['required', 'string', 'max:255'],
            'productReferenceCode' => $this->group === 'product' ? ['nullable', 'string', 'max:80'] : ['nullable'],
            'productSupplierId' => $this->group === 'product' ? [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'supplier')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ] : ['nullable'],
            'productSupplierIds' => $this->group === 'product' ? ['array', 'max:100'] : ['array'],
            'productSupplierIds.*' => $this->group === 'product' ? [
                'required', 'integer', 'distinct',
                Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'supplier')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ] : ['nullable'],
            'productFormMainCategory' => $this->group === 'product' ? ['required', 'string', 'max:255'] : ['nullable'],
            'productSize' => $this->group === 'product' ? ['nullable', 'string', 'max:1200'] : ['nullable'],
            'productPriceTable' => $this->group === 'product' ? ['nullable', 'string', 'max:50000'] : ['nullable'],
            'productRemoteSurchargeTable' => $this->group === 'product' ? ['nullable', 'string', 'max:50000'] : ['nullable'],
            'productCustomerPriceTable' => $this->group === 'product' ? ['nullable', 'string', 'max:50000'] : ['nullable'],
            'productCustomerPriceTiers' => $this->group === 'product' ? ['required', 'array', 'min:1', 'max:100'] : ['array'],
            'productCustomerPriceTiers.*.quantity' => $this->group === 'product' ? ['nullable', 'integer', 'min:1', 'max:100000000'] : ['nullable'],
            'productCustomerPriceTiers.*.price' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999999.99'] : ['nullable'],
            'productCustomerPriceCurrency' => $this->group === 'product' ? ['required', $currencyRule] : ['nullable'],
            'productClientPrices' => $this->group === 'product' ? ['array', 'max:100'] : ['array'],
            'productClientPrices.*.client_id' => $this->group === 'product' ? ['required', 'integer', 'distinct', Rule::exists('clients', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('purged_at'))] : ['nullable'],
            'productClientPrices.*.table' => $this->group === 'product' ? ['nullable', 'string', 'max:50000'] : ['nullable'],
            'productClientPrices.*.tiers' => $this->group === 'product' ? ['required', 'array', 'min:1', 'max:100'] : ['array'],
            'productClientPrices.*.tiers.*.quantity' => $this->group === 'product' ? ['nullable', 'integer', 'min:1', 'max:100000000'] : ['nullable'],
            'productClientPrices.*.tiers.*.price' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999999.99'] : ['nullable'],
            'productClientPrices.*.currency' => $this->group === 'product' ? ['required', $currencyRule] : ['nullable'],
            'productSupplierPrices' => $this->group === 'product' ? ['array', 'max:100'] : ['array'],
            'productSupplierPrices.*.supplier_id' => $this->group === 'product' ? ['required', 'integer', 'distinct'] : ['nullable'],
            'productSupplierPrices.*.tiers' => $this->group === 'product' ? ['required', 'array', 'min:1', 'max:100'] : ['array'],
            'productSupplierPrices.*.tiers.*.quantity' => $this->group === 'product' ? ['nullable', 'integer', 'min:1', 'max:100000000'] : ['nullable'],
            'productSupplierPrices.*.tiers.*.price' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999999.99'] : ['nullable'],
            'productSupplierPrices.*.tiers.*.remote_surcharge' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999999.99'] : ['nullable'],
            'productSupplierPrices.*.tiers.*.standard_shipment' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999999.99'] : ['nullable'],
            'productSupplierPrices.*.tiers.*.urgent_shipment' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999999.99'] : ['nullable'],
            'productSupplierPrices.*.currency' => $this->group === 'product' ? ['required', $currencyRule] : ['nullable'],
            // Keep the server-loaded baseline in the validated payload. Without
            // these rules, validate() strips both fields, treats every populated
            // supplier table as changed and falsely rejects all edits as stale.
            'productSupplierPrices.*.original_table' => $this->group === 'product' ? ['nullable', 'string', 'max:50000'] : ['nullable'],
            'productSupplierPrices.*.original_version' => $this->group === 'product' ? ['required', 'regex:/\A[a-f0-9]{64}\z/'] : ['nullable'],
            'productSupplierPrices.*.original_charges_version' => $this->group === 'product' ? ['required', 'regex:/\A[a-f0-9]{64}\z/'] : ['nullable'],
            'productOptions' => $this->group === 'product' ? ['array', 'max:30'] : ['array'],
            'productOptions.*.key' => $this->group === 'product' ? ['required', 'string', 'max:80'] : ['nullable'],
            'productOptions.*.label' => $this->group === 'product' ? ['required', 'string', 'max:120'] : ['nullable'],
            'productOptions.*.extra_charge' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999.999999'] : ['nullable'],
            'productOptionUploads' => $this->group === 'product' ? ['array'] : ['array'],
            'productOptionUploads.*' => $this->group === 'product' ? ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'] : ['nullable'],
            'productShipmentUrgencies' => $this->group === 'product' ? ['array', 'max:20'] : ['array'],
            'productShipmentUrgencies.*.key' => $this->group === 'product' ? ['required', 'string', 'max:80'] : ['nullable'],
            'productShipmentUrgencies.*.shipment_urgency_id' => $this->group === 'product' ? ['required', 'integer', Rule::exists('master_records', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'shipment_urgency')->whereNull('deleted_at'))] : ['nullable'],
            'productShipmentUrgencies.*.extra_charge' => $this->group === 'product' ? ['nullable', 'numeric', 'min:0', 'max:999999.999999'] : ['nullable'],
            'productSubcategory' => $this->group === 'product' ? ['nullable', 'string', 'max:255'] : ['nullable'],
            'productClientAvailabilityMode' => $this->group === 'product' ? ['required', Rule::in(['all', 'specific'])] : ['nullable'],
            'productClientIds' => $this->group === 'product' && $this->productClientAvailabilityMode === 'specific' ? ['required', 'array', 'min:1'] : ['array'],
            'productClientIds.*' => ['integer', Rule::exists('clients', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'productTestCertificateNumber' => $this->group === 'product' ? ['nullable', 'string', 'max:255'] : ['nullable'],
            'productCertificateUpload' => $this->group === 'product' ? AttachmentUpload::nullableRules(AttachmentUpload::PRODUCT_SUPPORTING, 10240) : ['nullable'],
            'productTemplateUpload' => $this->group === 'product' ? AttachmentUpload::nullableRules(AttachmentUpload::PRODUCT_SUPPORTING, 10240) : ['nullable'],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => in_array($this->group, MasterDataService::COLOR_TYPES, true)
                ? ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']
                : ['nullable'],
            'parentId' => match ($this->group) {
                'product' => [
                    'required',
                    'integer',
                    Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product_category')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))
                ],
                'state' => ['required', 'integer', Rule::exists('master_records', 'id')->where(fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'country')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))],
                default => ['nullable'],
            },
            'status' => ['required', 'in:active,inactive'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:1000000'],
            'metadataJson' => ['nullable', 'string'],
            'remoteAreaPostalCode' => ['nullable'],
            'remoteAreaCarrier' => $this->group === 'remote_area' ? ['required', 'string', 'max:80'] : ['nullable'],
            'remoteAreaCountry' => $this->group === 'remote_area' ? ['required', 'string', 'max:120'] : ['nullable'],
            'remoteAreaIataCode' => $this->group === 'remote_area' ? ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'] : ['nullable'],
            'remoteAreaPostalCodeFrom' => $this->group === 'remote_area' ? ['nullable', 'string', 'max:32', 'required_without:remoteAreaCity'] : ['nullable'],
            'remoteAreaPostalCodeTo' => $this->group === 'remote_area' ? ['nullable', 'string', 'max:32'] : ['nullable'],
            'remoteAreaCity' => $this->group === 'remote_area' ? ['nullable', 'string', 'max:120', 'required_without:remoteAreaPostalCodeFrom'] : ['nullable'],
            'remoteAreaOriginSurcharge' => $this->group === 'remote_area' ? ['required', Rule::in(['No', 'Extended Area Surcharge', 'Pickup Area Surcharge', 'Pickup Area Surcharge - Extended', 'Remote Area Surcharge', 'Remote Area Surcharge - Extended'])] : ['nullable'],
            'remoteAreaDestinationSurcharge' => $this->group === 'remote_area' ? ['required', Rule::in(['No', 'Extended Area Surcharge', 'Delivery Area Surcharge', 'Delivery Area Surcharge - Extended', 'Remote Area Surcharge', 'Remote Area Surcharge - Extended'])] : ['nullable'],
            'remoteAreaExtraCharge' => $this->group === 'remote_area' ? ['nullable', 'numeric', 'min:0', 'max:999999.99'] : ['nullable'],
            'workCalendarDayFrom' => $this->group === 'task_pack_work_calendar'
                ? ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])]
                : ['nullable'],
            'workCalendarDayTo' => $this->group === 'task_pack_work_calendar'
                ? ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])]
                : ['nullable'],
            'workCalendarTimeFrom' => $this->group === 'task_pack_work_calendar' ? ['required', 'date_format:H:i'] : ['nullable'],
            'workCalendarTimeTo' => $this->group === 'task_pack_work_calendar' ? ['required', 'date_format:H:i', 'after:workCalendarTimeFrom'] : ['nullable'],
            'autoInquiryStatus' => $this->group === 'inquiry_task_status'
                ? ['required', Rule::in(['To do', 'In Progress', 'Completed', 'Cancelled', '__task_status__'])]
                : ['nullable'],
            'requiresAttention' => $this->group === 'inquiry_task_status' ? ['boolean'] : ['nullable'],
            'orderTaskFlagId' => $this->group === 'order_task_status'
                ? ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'order_task_flag')->where('status', 'active')->whereNull('deleted_at'))]
                : ['nullable'],
            'orderFlagId' => $this->group === 'order_task_flag'
                ? ['required', 'integer', Rule::exists('master_records', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)->where('type', 'order_flag')->where('status', 'active')->whereNull('deleted_at'))]
                : ['nullable'],
            'productImage' => $this->group === 'product'
                ? ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']
                : ['nullable'],
        ]);

        if ($this->group === 'product') {
            $taxonomy = app(\App\Services\ProductTaxonomyService::class);
            $main = $taxonomy->mainCategories(true)
                ->first(fn (MasterRecord $record) => mb_strtolower(trim($record->name)) === mb_strtolower(trim((string) $data['productFormMainCategory'])));
            $category = $taxonomy->productCategories(true)->firstWhere('id', (int) $data['parentId']);

            if (!$main) {
                throw ValidationException::withMessages([
                    'productFormMainCategory' => 'Select an active main category from Product Categories.',
                ]);
            }
            if (!$category || (int) ($taxonomy->mainCategoryFor($category)?->id ?? 0) !== (int) $main->id) {
                throw ValidationException::withMessages([
                    'parentId' => 'Select a product category that belongs to the selected main category.',
                ]);
            }

            $subcategoryName = trim((string) $data['productSubcategory']);
            if ($subcategoryName !== '') {
                $validSubcategory = $taxonomy->subcategories(true)->contains(
                    fn (MasterRecord $record) => (int) $record->parent_id === (int) $category->id
                        && mb_strtolower(trim($record->name)) === mb_strtolower($subcategoryName)
                );
                if (!$validSubcategory) {
                    throw ValidationException::withMessages([
                        'productSubcategory' => 'Select a subcategory that belongs to the selected product category.',
                    ]);
                }
            }

            // Persist the canonical main-category spelling even if an old Product
            // row was opened with legacy metadata/casing.
            $data['productFormMainCategory'] = $main->name;

            $shipmentUrgencyIds = collect($data['productShipmentUrgencies'] ?? [])
                ->pluck('shipment_urgency_id')
                ->map(fn ($value) => (int) $value)
                ->filter();
            if ($shipmentUrgencyIds->count() !== $shipmentUrgencyIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'productShipmentUrgencies' => 'Each shipping urgency can only be added once to a product.',
                ]);
            }
        }

        $productPriceBreakpoints = [];
        $productRemoteSurchargeBreakpoints = [];
        $productCustomerPriceBreakpoints = [];
        if ($this->group === 'product' && trim((string) $data['productPriceTable']) !== '') {
            $parsedPriceTable = app(ProductPriceTableParser::class)->parseTable($data['productPriceTable']);
            $productPriceBreakpoints = $parsedPriceTable['price_breakpoints'];
            $productRemoteSurchargeBreakpoints = $parsedPriceTable['remote_surcharge_breakpoints'];
            if ($productPriceBreakpoints === []) {
                throw ValidationException::withMessages([
                    'productPriceTable' => 'Paste a valid price table containing quantity and product price values.',
                ]);
            }
        }

        // The remote surcharge belongs to the product, independently of the
        // supplier cost table. Do not erase imported/legacy surcharges merely
        // because a supplier cost table is blank or access is restricted.
        if ($this->group === 'product') {
            $remoteTable = trim((string) ($data['productRemoteSurchargeTable'] ?? ''));
            if ($remoteTable !== '') {
                $productRemoteSurchargeBreakpoints = app(ProductPriceTableParser::class)
                    ->parseTable($remoteTable)['price_breakpoints'];
                if ($productRemoteSurchargeBreakpoints === []) {
                    throw ValidationException::withMessages(['productRemoteSurchargeTable' => 'Enter valid quantities and surcharge prices.']);
                }
            }
        }

        // Convert only the client grids into the existing legacy table format.
        // No new storage, pricing calculations or supplier-price behavior.
        $validatedClientPriceTables = [];
        if ($this->group === 'product' && $canEditClientPrices) {
            $clientMatrix = app(ClientPriceMatrix::class);
            try {
                $productCustomerPriceBreakpoints = $clientMatrix->parseEditableTiers($data['productCustomerPriceTiers']);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['productCustomerPriceTiers' => $exception->getMessage()]);
            }
            $data['productCustomerPriceTable'] = $clientMatrix->toLegacyTable($productCustomerPriceBreakpoints);

            foreach ($data['productClientPrices'] as $index => $row) {
                try {
                    $breakpoints = $clientMatrix->parseEditableTiers($row['tiers'] ?? []);
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        "productClientPrices.$index.tiers" => $exception->getMessage(),
                    ]);
                }
                if ($breakpoints === []) continue; // Empty matrix is shown, not stored.
                $validatedClientPriceTables[] = [
                    'client_id' => (int) $row['client_id'],
                    'price_table_raw' => $clientMatrix->toLegacyTable($breakpoints),
                    'price_breakpoints' => json_encode($breakpoints, JSON_THROW_ON_ERROR),
                ];
            }
        }

        // Only edited supplier tables are written. Unmodified approved prices remain authoritative.
        $supplierPriceEdits = [];
        if ($this->group === 'product' && $canEditSupplierPricesForPortal && app(\App\Services\Orders\SupplierCostVisibility::class)->allowed()) {
            $taggedSupplierIds = collect($data['productSupplierIds'] ?? [])
                ->push($data['productSupplierId'] ?? null)
                ->map(fn ($id) => (int) $id)->filter()->unique()->all();
            $pricing = app(\App\Services\SupplierBridge\SupplierPricing::class);
            $matrix = app(\App\Services\SupplierBridge\SupplierPriceMatrix::class);
            foreach ($data['productSupplierPrices'] as $index => $row) {
                $supplierId = (int) $row['supplier_id'];
                if (! in_array($supplierId, $taggedSupplierIds, true)) {
                    throw ValidationException::withMessages(["productSupplierPrices.$index.supplier_id" => 'Select a tagged supplier.']);
                }
                $original = app(ProductPriceTableParser::class)
                    ->parseTable((string) ($row['original_table'] ?? ''))['price_breakpoints'];
                try {
                    $edited = $matrix->parseEditableTiers($row['tiers'] ?? []);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(["productSupplierPrices.$index.tiers" =>
                        $exception->errors()['productSupplierPrices'][0] ?? 'Invalid supplier pricing table.']);
                }
                if ($edited['rows'] === []) continue; // An untouched empty grid does not erase approved prices.
                $parsed = $pricing->normalize($edited['rows']);
                $priceChanged = $parsed !== $pricing->normalize($original);
                // Charge data has its own baseline, independent of supplier approvals.
                $chargesChanged = ! hash_equals((string) ($row['original_charges_version'] ?? ''),
                    $matrix->chargesVersion($edited['charges']));
                if (! $priceChanged && ! $chargesChanged) continue;
                $supplierPriceEdits[] = [
                    'supplier_id' => $supplierId, 'rows' => $parsed, 'charges' => $edited['charges'],
                    'price_changed' => $priceChanged, 'charges_changed' => $chargesChanged,
                    'base_version' => (string) ($row['original_version'] ?? ''),
                    'charges_base_version' => (string) ($row['original_charges_version'] ?? ''),
                ];
            }
        }

        $metadata = null;
        if (filled($data['metadataJson'])) {
            $metadata = json_decode($data['metadataJson'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($metadata)) {
                throw ValidationException::withMessages(['metadataJson' => 'Metadata must be valid JSON.']);
            }
        }

        if ($this->group === 'remote_area') {
            $metadata ??= [];
            $postalFrom = $service->normalizePostalCode((string) ($data['remoteAreaPostalCodeFrom'] ?? ''));
            $postalTo = $service->normalizePostalCode((string) ($data['remoteAreaPostalCodeTo'] ?? ''));
            if ($postalFrom === '' && $postalTo !== '') {
                throw ValidationException::withMessages([
                    'remoteAreaPostalCodeTo' => 'Enter Postal Code From before Postal Code To.',
                ]);
            }
            if ($postalFrom !== '' && $postalTo === '') {
                $postalTo = $postalFrom;
            }
            if ($postalFrom !== '' && $postalTo !== '' && $service->postalRangeIsDescending($postalFrom, $postalTo)) {
                throw ValidationException::withMessages([
                    'remoteAreaPostalCodeTo' => 'Postal Code To must be greater than or equal to Postal Code From.',
                ]);
            }

            $metadata['carrier'] = trim((string) $data['remoteAreaCarrier']);
            $metadata['country'] = trim((string) $data['remoteAreaCountry']);
            $metadata['iata_code'] = strtoupper(trim((string) $data['remoteAreaIataCode']));
            $metadata['postal_code_from'] = $postalFrom ?: null;
            $metadata['postal_code_to'] = $postalTo ?: null;
            $metadata['city'] = trim((string) ($data['remoteAreaCity'] ?? '')) ?: null;
            $metadata['origin_surcharge'] = $data['remoteAreaOriginSurcharge'];
            $metadata['destination_surcharge'] = $data['remoteAreaDestinationSurcharge'];

            // Preserve metadata.postal_code only on historical rows that
            // already carried it. New UPS-style rows are matched directly from
            // postal_code_from / postal_code_to and do not need duplication.
            $hadLegacyExactPostalCode = trim((string) ($metadata['postal_code'] ?? '')) !== '';
            if ($hadLegacyExactPostalCode && $postalFrom !== '' && $postalFrom === $postalTo) {
                $metadata['postal_code'] = $postalFrom;
            } else {
                unset($metadata['postal_code']);
            }

            if ($data['remoteAreaExtraCharge'] === null || trim((string) $data['remoteAreaExtraCharge']) === '') {
                unset($metadata['extra_charge']);
            } else {
                $metadata['extra_charge'] = round((float) $data['remoteAreaExtraCharge'], 2);
            }
            $metadata = array_filter($metadata, fn ($value) => $value !== null && $value !== '');
        }

        if ($this->group === 'task_pack_work_calendar') {
            $metadata ??= [];
            $metadata['day_from'] = $data['workCalendarDayFrom'];
            $metadata['day_to'] = $data['workCalendarDayTo'];
            $metadata['time_from'] = $data['workCalendarTimeFrom'];
            $metadata['time_to'] = $data['workCalendarTimeTo'];
        }

        if ($this->group === 'inquiry_task_status') {
            $metadata ??= [];
            $metadata['auto_inquiry_status'] = $data['autoInquiryStatus'];
            $metadata['requires_attention'] = (bool) $data['requiresAttention'];
        }

        if ($this->group === 'order_task_status') {
            $metadata ??= [];
            if ($data['orderTaskFlagId']) {
                $metadata['order_task_flag_id'] = (int) $data['orderTaskFlagId'];
            } else {
                unset($metadata['order_task_flag_id']);
            }
        }

        if ($this->group === 'order_task_flag') {
            $metadata ??= [];
            $metadata['order_flag_id'] = (int) $data['orderFlagId'];
        }

        if ($this->group === 'product') {
            $metadata ??= [];
            $metadata['reference_code'] = trim((string) $data['productReferenceCode']);
            $linkedSupplierIds = collect($data['productSupplierIds'] ?? [])
                ->map(fn ($id) => (int) $id)->filter()->unique();
            if (filled($data['productSupplierId'] ?? null)) {
                $defaultSupplierId = (int) $data['productSupplierId'];
                $metadata['supplier_id'] = $defaultSupplierId;
                unset($metadata['default_supplier_id']);
                // Choosing the default supplier also links it to this product.
                $linkedSupplierIds->push($defaultSupplierId);
            } else {
                unset($metadata['supplier_id'], $metadata['default_supplier_id']);
            }
            $metadata['supplier_ids'] = $linkedSupplierIds->unique()->values()->all();
            $metadata['main_category'] = trim((string) $data['productFormMainCategory']);
            $metadata['product_size'] = trim((string) $data['productSize']) ?: null;
            if ($canEditClientPrices) {
            $metadata['customer_price_currency'] = $data['productCustomerPriceCurrency'];
            $clientCurrencies = (array) ($metadata['client_price_currencies'] ?? []);
            foreach ($data['productClientPrices'] as $row) {
                $clientId = (int) $row['client_id'];
                if ($data['productClientAvailabilityMode'] !== 'specific'
                    || ! in_array($clientId, array_map('intval', $data['productClientIds'] ?? []), true)) continue;
                if ($row['currency'] === $data['productCustomerPriceCurrency']) {
                    unset($clientCurrencies[$clientId]);
                } else {
                    $clientCurrencies[$clientId] = $row['currency'];
                }
            }
            if ($clientCurrencies !== []) {
                $metadata['client_price_currencies'] = $clientCurrencies;
            } else {
                unset($metadata['client_price_currencies']);
            }
            } // Client pricing remains unchanged when portal permission is absent.
            $canEditSupplierPrices = $canEditSupplierPricesForPortal && app(\App\Services\Orders\SupplierCostVisibility::class)->allowed();
            if (! $canEditSupplierPrices) {
                // Only the existing persisted source can populate confidential
                // supplier metadata. Ignore/reject untrusted Livewire price state.
                if (filled($data['productPriceTable']) || $data['productSupplierPrices'] !== []) {
                    abort(403, 'Supplier price permission required.');
                }
                if ($this->editId) {
                    $persisted = MasterRecord::query()->forWorkspace($workspaceId)
                        ->ofType('product')->findOrFail($this->editId);
                    foreach (['price_table_raw', 'price_breakpoints', 'supplier_price_currency', 'supplier_price_currencies'] as $key) {
                        if (array_key_exists($key, (array) $persisted->metadata)) {
                            $metadata[$key] = $persisted->metadata[$key];
                        } else {
                            unset($metadata[$key]);
                        }
                    }
                } else {
                    unset($metadata['supplier_price_currency'], $metadata['supplier_price_currencies']);
                }
            } else {
                $supplierCurrencies = (array) ($metadata['supplier_price_currencies'] ?? []);
                $baseCurrency = strtoupper(trim((string) ($metadata['supplier_price_currency'] ?? 'USD'))) ?: 'USD';
                foreach ($data['productSupplierPrices'] as $row) {
                    $supplierId = (int) $row['supplier_id'];
                    if ($row['currency'] === $baseCurrency) {
                        unset($supplierCurrencies[$supplierId]);
                    } else {
                        $supplierCurrencies[$supplierId] = $row['currency'];
                    }
                }
                if ($supplierCurrencies !== []) {
                    $metadata['supplier_price_currencies'] = $supplierCurrencies;
                } else {
                    unset($metadata['supplier_price_currencies']);
                }
            }
            if ($canEditSupplierPrices) {
                if ($productPriceBreakpoints !== []) {
                    $metadata['price_table_raw'] = trim((string) $data['productPriceTable']);
                    $metadata['price_breakpoints'] = $productPriceBreakpoints;
                } else {
                    unset($metadata['price_table_raw'], $metadata['price_breakpoints']);
                }
            }
            if ($productRemoteSurchargeBreakpoints !== []) {
                $metadata['remote_surcharge_breakpoints'] = $productRemoteSurchargeBreakpoints;
            } else {
                unset($metadata['remote_surcharge_breakpoints']);
            }
            if ($canEditClientPrices && $productCustomerPriceBreakpoints !== []) {
                $metadata['customer_price_table_raw'] = trim((string) $data['productCustomerPriceTable']);
                $metadata['customer_price_breakpoints'] = $productCustomerPriceBreakpoints;
            } elseif ($canEditClientPrices) {
                unset($metadata['customer_price_table_raw'], $metadata['customer_price_breakpoints']);
            }
            $metadata['sub_category'] = trim((string) $data['productSubcategory']) ?: null;
            unset($metadata['taxonomy_unassigned']);
            $metadata['test_certificate_number'] = trim((string) $data['productTestCertificateNumber']) ?: null;
            $metadata['client_availability'] = $data['productClientAvailabilityMode'];
            if ($data['productClientAvailabilityMode'] === 'specific') {
                $clients = Client::query()->whereIn('id', $data['productClientIds'])->orderBy('name')->get(['id', 'name', 'code']);
                $metadata['client_ids'] = $clients->pluck('id')->map(fn ($value) => (int) $value)->values()->all();
                $metadata['client_availability_labels'] = $clients->pluck('name')->values()->all();
                $metadata['client_codes'] = $clients->pluck('code')->filter()->values()->all();
            } else {
                unset($metadata['client_ids'], $metadata['client_availability_labels'], $metadata['client_codes']);
            }

            $shipmentUrgencyRows = collect($data['productShipmentUrgencies'] ?? []);
            if ($shipmentUrgencyRows->isNotEmpty()) {
                $shipmentUrgencies = MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('shipment_urgency')
                    ->whereIn('id', $shipmentUrgencyRows->pluck('shipment_urgency_id')->map(fn ($value) => (int) $value)->all())
                    ->get(['id', 'code', 'name'])
                    ->keyBy('id');

                $metadata['shipment_urgency_options'] = $shipmentUrgencyRows->map(function (array $row) use ($shipmentUrgencies): array {
                    $urgencyId = (int) ($row['shipment_urgency_id'] ?? 0);
                    $urgency = $shipmentUrgencies->get($urgencyId);

                    return [
                        'key' => (string) ($row['key'] ?? Str::uuid()),
                        'shipment_urgency_id' => $urgencyId,
                        'shipment_urgency_code' => (string) ($urgency?->code ?? ''),
                        'shipment_urgency_name' => (string) ($urgency?->name ?? ''),
                        'extra_charge' => max(0, (float) ($row['extra_charge'] ?? 0)),
                    ];
                })->values()->all();
            } else {
                unset($metadata['shipment_urgency_options']);
            }
            // Remove the earlier incorrect shipment-method based product metadata.
            unset($metadata['shipping_options']);

            $metadata = array_filter($metadata, fn ($value, $key) => $key === 'reference_code' || ($value !== null && $value !== ''), ARRAY_FILTER_USE_BOTH);
        }

        if ($portalActor) {
            $originalMetadata = (array) ($portalOriginal?->metadata ?? []);
            $restricted = [
                'client_pricing' => ['customer_price_table_raw', 'customer_price_breakpoints',
                    'customer_price_currency', 'client_price_currencies'],
                // Supplier portals can edit their own supplier row, never the
                // base fallback or another supplier's currency override.
                'supplier_base' => ['price_table_raw', 'price_breakpoints', 'supplier_price_currency'],
                'shipment' => ['remote_surcharge_breakpoints', 'shipment_urgency_options', 'shipping_options'],
                'availability' => ['client_availability', 'client_ids', 'client_availability_labels', 'client_codes'],
                'options' => ['options', 'product_options'],
                'documents' => ['test_certificate_number', 'product_image_path',
                    'certificate_test_report_path', 'certificate_test_report',
                    'template_doc_path', 'template_doc', 'template_doc_url', 'certificate_test_report_url'],
            ];
            foreach ($restricted as $section => $fields) {
                if ($section !== 'supplier_base' && $portalAccess->can(auth()->user(), $section, 'edit')) continue;
                foreach ($fields as $field) {
                    if (array_key_exists($field, $originalMetadata)) $metadata[$field] = $originalMetadata[$field];
                    else unset($metadata[$field]);
                }
            }
            if (! $portalAccess->can(auth()->user(), 'supplier_pricing', 'edit')) {
                if (array_key_exists('supplier_price_currencies', $originalMetadata)) {
                    $metadata['supplier_price_currencies'] = $originalMetadata['supplier_price_currencies'];
                } else unset($metadata['supplier_price_currencies']);
            } else {
                // Preserve all other suppliers' currency selections.
                $currencies = (array) ($originalMetadata['supplier_price_currencies'] ?? []);
                $myId = (int) $portalAccess->supplierId(auth()->user());
                foreach ($data['productSupplierPrices'] as $row) {
                    if ((int) $row['supplier_id'] !== $myId) continue;
                    $currency = $row['currency'];
                    $default = strtoupper((string) ($originalMetadata['supplier_price_currency'] ?? 'USD'));
                    if ($currency === $default) unset($currencies[$myId]);
                    else $currencies[$myId] = $currency;
                }
                if ($currencies !== []) $metadata['supplier_price_currencies'] = $currencies;
                else unset($metadata['supplier_price_currencies']);
            }
        }

        if ($this->group === 'product' && $this->editId) {
            $existing = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->findOrFail($this->editId);
            foreach (['product_image_path', 'certificate_test_report_path', 'certificate_test_report', 'template_doc_path', 'template_doc'] as $key) {
                $value = data_get($existing->metadata, $key);
                if (filled($value)) {
                    $metadata ??= [];
                    $metadata[$key] = $value;
                }
            }
        }

        $previousSupplierIds = [];
        if ($this->group === 'product' && $this->editId && config('supplier_bridge.enabled')) {
            $previousSupplierIds = DB::table('product_supplier_links')
                ->where('product_id', $this->editId)->where('workspace_id', $workspaceId)
                ->pluck('supplier_id')->map(fn ($id) => (int) $id)->all();
            $previousSupplierIds = array_merge($previousSupplierIds, $existing->productSupplierIds());
        }
        $wasCreating = !$this->editId;
        $saveRecord = function () use ($data, $metadata, $workspaceId, $validatedClientPriceTables, $supplierPriceEdits, $canEditClientPrices) {
            $supplierEditsToApply = $supplierPriceEdits;
            if ($this->group === 'product' && $this->editId && $supplierPriceEdits !== []) {
                // Lock and compare the SAVED record before writing product metadata.
                // Comparing afterwards can generate a false "prices changed" error
                // when a legacy fallback table is also normalized during save.
                // Lock order matches the review API (product first).
                $savedProduct = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')
                    ->whereKey($this->editId)->lockForUpdate()->firstOrFail();
                $pricing = app(\App\Services\SupplierBridge\SupplierPricing::class);
                $currentTables = DB::table('supplier_product_prices')
                    ->where('workspace_id', $workspaceId)
                    ->where('product_id', $savedProduct->id)
                    ->whereIn('supplier_id', array_column($supplierPriceEdits, 'supplier_id'))
                    ->get(['supplier_id', 'price_breakpoints', 'additional_charges'])->keyBy('supplier_id');
                $fallback = $savedProduct->supplierPriceBreakpoints();
                $matrix = app(\App\Services\SupplierBridge\SupplierPriceMatrix::class);
                foreach ($supplierPriceEdits as $index => $edit) {
                    $override = $currentTables->get($edit['supplier_id']);
                    $rows = $pricing->normalize($override !== null
                        ? (json_decode((string) $override->price_breakpoints, true) ?: []) : $fallback);
                    $charges = $matrix->normalizeCharges(json_decode((string) ($override->additional_charges ?? ''), true) ?: []);
                    if ($edit['price_changed'] && ! hash_equals($pricing->version($rows), $edit['base_version'])) {
                        // The same price tiers may already have been approved or
                        // saved in another request. That is an idempotent no-op,
                        // not a conflicting change; do not write them again.
                        if ($rows === $edit['rows']) {
                            $supplierEditsToApply[$index]['price_changed'] = false;
                        } else {
                            throw ValidationException::withMessages(['productSupplierPrices' =>
                                'Supplier prices changed since this product was opened. Reload the supplier prices to review the latest approved costs before saving.']);
                        }
                    }
                    if (! $edit['price_changed'] && $edit['charges_changed']
                        && ! hash_equals($pricing->version($rows), $edit['base_version'])) {
                        throw ValidationException::withMessages(['productSupplierPrices' =>
                            'Supplier quantity tiers changed since this product was opened. Reload supplier prices before editing shipment charges.']);
                    }
                    if ($edit['charges_changed'] && ! hash_equals($matrix->chargesVersion($charges), $edit['charges_base_version'])) {
                        if ($charges === $edit['charges']) {
                            $supplierEditsToApply[$index]['charges_changed'] = false;
                        } else {
                            throw ValidationException::withMessages(['productSupplierPrices' =>
                                'Supplier charges changed since this product was opened. Reload supplier prices before saving.']);
                        }
                    }
                    if (! $supplierEditsToApply[$index]['price_changed'] && ! $supplierEditsToApply[$index]['charges_changed']) {
                        unset($supplierEditsToApply[$index]);
                        continue;
                    }
                    // A charge-only edit must never undo a newer approved unit price.
                    if (! $supplierEditsToApply[$index]['price_changed']) {
                        $supplierEditsToApply[$index]['rows'] = $rows;
                    }
                    if (! $supplierEditsToApply[$index]['charges_changed']) {
                        $supplierEditsToApply[$index]['charges'] = $charges;
                    }
                }
            }

            // Currency-specific extra matrices must never collide with a newly
            // selected primary currency. Existing order/pricing consumers rely on
            // exactly one primary approved matrix per supplier.
            if ($this->group === 'product' && $this->editId) {
                $original = MasterRecord::query()->forWorkspace($workspaceId)
                    ->ofType('product')->whereKey($this->editId)->firstOrFail();
                $changes = [];
                foreach ((array) ($metadata['supplier_ids'] ?? []) as $linkedSupplierId) {
                    $linkedSupplierId = (int) $linkedSupplierId;
                    $newCurrency = strtoupper((string) (
                        data_get($metadata, 'supplier_price_currencies.'.$linkedSupplierId)
                        ?: ($metadata['supplier_price_currency'] ?? 'USD')));
                    if ($newCurrency !== $original->supplierPriceCurrency($linkedSupplierId)) {
                        $changes[$linkedSupplierId] = $newCurrency;
                    }
                }
                if ($changes !== []) {
                    $overlaps = DB::table('supplier_product_currency_tables')
                        ->where('workspace_id', $workspaceId)->where('product_id', $this->editId)
                        ->whereIn('supplier_id', array_keys($changes))
                        ->get(['supplier_id', 'currency']);
                    foreach ($overlaps as $overlap) {
                        if ($changes[(int)$overlap->supplier_id] === $overlap->currency) {
                            throw ValidationException::withMessages(['productSupplierPrices' =>
                                'An additional table already uses the selected primary currency. Remove that additional table first.']);
                        }
                    }
                }
            }

            $record = app(\App\Actions\MasterData\SaveMasterRecordAction::class)->execute($this->group, [
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'],
                'color' => in_array($this->group, MasterDataService::COLOR_TYPES, true) ? strtoupper($data['color']) : null,
                'parent_id' => in_array($this->group, ['product', 'state'], true) ? $data['parentId'] : null,
                'status' => $data['status'],
                'sort_order' => $data['sortOrder'],
                'metadata' => $metadata,
            ], $this->editId);

            if ($this->group === 'product' && Schema::hasTable('product_supplier_links')) {
                $supplierIds = collect($metadata['supplier_ids'])->map(fn ($id) => (int) $id)->all();
                $links = DB::table('product_supplier_links')
                    ->where('workspace_id', $workspaceId)
                    ->where('product_id', $record->id);
                $supplierIds === [] ? $links->delete() : $links->whereNotIn('supplier_id', $supplierIds)->delete();
                $now = now();
                DB::table('product_supplier_links')->insertOrIgnore(array_map(fn (int $supplierId): array => [
                    'workspace_id' => $workspaceId,
                    'product_id' => $record->id,
                    'supplier_id' => $supplierId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $supplierIds));
            }
            if ($this->group === 'product' && $supplierEditsToApply !== []) {
                foreach ($supplierEditsToApply as $edit) {
                    $id = $edit['supplier_id'];
                    DB::table('supplier_product_prices')->upsert([[
                        'workspace_id' => $workspaceId,
                        'product_id' => (int) $record->id,
                        'supplier_id' => $id,
                        'price_breakpoints' => json_encode($edit['rows'], JSON_THROW_ON_ERROR),
                        'additional_charges' => json_encode($edit['charges'], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]], ['supplier_id', 'product_id'], ['price_breakpoints', 'additional_charges', 'updated_at']);
                    if (! $edit['price_changed']) continue; // Charges must not stale pending supplier price proposals.
                    DB::table('supplier_price_proposals')->where('workspace_id', $workspaceId)
                        ->where('product_id', $record->id)->where('supplier_id', $id)
                        ->where('status', 'pending')->update([
                            'status' => 'stale', 'review_note' => 'FlowTracker changed these supplier prices directly.',
                            'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'updated_at' => now(),
                        ]);
                }
            }
            if ($this->group === 'product' && $canEditClientPrices) {
                $clientIds = array_column($validatedClientPriceTables, 'client_id');
                $query = DB::table('product_client_prices')->where('workspace_id', $workspaceId)->where('product_id', $record->id);
                $clientIds === [] ? $query->delete() : $query->whereNotIn('client_id', $clientIds)->delete();
                if ($validatedClientPriceTables !== []) {
                    $now = now();
                    DB::table('product_client_prices')->upsert(
                        array_map(fn (array $row): array => array_merge($row, [
                            'workspace_id' => $workspaceId,
                            'product_id' => (int) $record->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]), $validatedClientPriceTables),
                        ['product_id', 'client_id'],
                        ['price_table_raw', 'price_breakpoints', 'updated_at']
                    );
                }
            }
            return $record;
        };
        $record = $this->group === 'product' ? DB::transaction($saveRecord) : $saveRecord();

        if ($this->group === 'product' && config('supplier_bridge.enabled')) {
            app(\App\Services\SupplierBridge\MarkCatalogDirty::class)->supplierIds(
                $workspaceId, array_merge($previousSupplierIds, (array) ($metadata['supplier_ids'] ?? [])),
            );
        }


        if ($this->group === 'product') {
            try {
                $imageService = app(ProductImageService::class);
                if ($this->productImage) {
                    $record = $imageService->replace($record, $this->productImage);
                } elseif ($this->removeProductImage && data_get($record->metadata, 'product_image_path')) {
                    $record = $imageService->remove($record);
                }
            } catch (\Throwable $exception) {
                report($exception);
                // If the database row was just created, keep this modal attached
                // to that row so a retry updates it instead of creating a duplicate.
                if ($wasCreating) {
                    $this->editId = $record->id;
                    $this->existingProductImageUrl = $record->productImageUrl();
                }
                $this->addError('productImage', 'The product was saved, but its image could not be stored. Please try the image again.');
                return;
            }
        }

        if ($this->group === 'product'
            && (! $portalActor || $portalAccess->can(auth()->user(), 'options', 'edit'))) {
            try {
                $record = app(ProductOptionImageService::class)->sync(
                    $record,
                    $data['productOptions'] ?? [],
                    $this->productOptionUploads,
                );
            } catch (\Throwable $exception) {
                report($exception);
                if ($wasCreating) $this->editId = $record->id;
                $this->addError('productOptions', 'The product was saved, but an option image could not be stored. Please try the upload again.');
                return;
            }
        }

        if ($this->group === 'product'
            && (! $portalActor || $portalAccess->can(auth()->user(), 'documents', 'edit'))) {
            try {
                $record = app(\App\Services\ProductDocumentService::class)->sync(
                    $record,
                    $this->productCertificateUpload,
                    $this->productTemplateUpload,
                    $this->removeProductCertificate,
                    $this->removeProductTemplate,
                );
            } catch (\Throwable $exception) {
                report($exception);
                if ($wasCreating) $this->editId = $record->id;
                $this->addError('productCertificateUpload', 'The product was saved, but a supporting document could not be stored. Please try the upload again.');
                return;
            }
        }

        $this->showModal = false;
        $this->productImage = null;
        $this->existingProductImageUrl = null;
        $this->removeProductImage = false;
        $this->productCertificateUpload = null;
        $this->productTemplateUpload = null;
        $this->productOptions = [];
        $this->productOptionUploads = [];
        $this->productShipmentUrgencies = [];
        $this->productShipmentUrgencyPickerOpen = false;
        $this->productShipmentUrgencyPickerSelection = [];
        $this->removeProductCertificate = false;
        $this->removeProductTemplate = false;
        session()->flash('success', $this->group === 'product' ? 'Product saved.' : 'Master data saved.');
        app(\App\Services\NotificationService::class)->notifyUser(
            auth()->user(),
            'Master data updated',
            ($this->name ?: $this->code).' was saved in '.(MasterDataService::LABELS[$this->group] ?? 'Master Data').'.',
            'update',
            null,
            null,
            auth()->user(),
        );
    }
    private function remoteAreaGeneratedName(): string
    {
        $country = trim($this->remoteAreaCountry);
        $city = trim($this->remoteAreaCity);
        $from = app(MasterDataService::class)->normalizePostalCode($this->remoteAreaPostalCodeFrom);
        $to = app(MasterDataService::class)->normalizePostalCode($this->remoteAreaPostalCodeTo);
        if ($from !== '' && $to === '') $to = $from;

        $location = $city;
        if ($location === '' && $from !== '') {
            $location = ($to !== '' && $to !== $from) ? $from.'–'.$to : $from;
        }

        $label = collect([$country, $location])->filter()->implode(' · ');
        return $label !== '' ? $label : 'Remote Area Surcharge';
    }

    public function updateColor(int $id, string $color): void
    {
        if (app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)->isPortalActor(auth()->user())) {
            $this->authorizeGroupAction('edit');
        }
        $this->recordsReady = true;
        $record = $this->currentGroupRecord($id);
        app(\App\Actions\MasterData\UpdateMasterColorAction::class)->execute($record->id, $color);
    }
    public function toggle(int $id): void
    {
        if (app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)->isPortalActor(auth()->user())) {
            $this->authorizeGroupAction('edit');
        }
        $this->recordsReady = true;
        $record = $this->currentGroupRecord($id);
        app(\App\Actions\MasterData\ToggleMasterRecordAction::class)->execute($record->id);
        session()->flash('success', 'Master data status updated.');
        app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Master data status updated', $record->name.' status was changed.', 'update', null, null, auth()->user());
    }
    public function deleteRecord(int $id): void
    {
        if (app(\App\Services\SupplierBridge\SupplierProductModuleAccess::class)->isPortalActor(auth()->user())) {
            $this->authorizeGroupAction('delete');
        }
        $this->recordsReady = true;
        $record = $this->currentGroupRecord($id);
        try {
            app(\App\Actions\MasterData\DeleteMasterRecordAction::class)->execute($record->id);
            $this->resetPage('masterPage');
            session()->flash('success', 'Master data record deleted.');
            app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Master data deleted', 'A master data record was deleted.', 'update', null, null, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('record', collect($e->errors())->flatten()->first());
        }
    }
}
