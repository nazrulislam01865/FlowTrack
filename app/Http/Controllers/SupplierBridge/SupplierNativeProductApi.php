<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\MasterRecord;
use App\Models\MasterValue;
use Illuminate\Support\Facades\Schema;
use App\Services\ClientPriceMatrix;
use App\Services\SupplierBridge\SupplierPriceMatrix;
use App\Services\SupplierBridge\SupplierCurrencyPriceTables;
use App\Services\SupplierBridge\SupplierPricing;
use App\Services\SupplierBridge\SupplierProductModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stateless, API-key-authenticated product workspace for Supplier Portal.
 * No iframe, FlowTracker browser session, shared database credentials or
 * client-side API secrets. Every operation is scoped and authorized here.
 */
final class SupplierNativeProductApi extends Controller
{
    use VerifySupplierProductApi;

    public function __invoke(Request $request, SupplierProductModuleAccess $access): JsonResponse
    {
        $payload = $this->signedProductApi($request, 9000000);
        $data = validator($payload, [
            'event_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'supplier_user_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:catalog,detail,lookups,save_product,delete_product,save_category,delete_category,save_pricing,asset,upload_asset'],
            'id' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:10,20,50,100'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'main_category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'availability' => ['sometimes', 'nullable', 'in:all,specific'],
            'group' => ['sometimes', 'in:product,product_category'],
            'data' => ['sometimes', 'array'],
        ])->validate();
        $supplier = MasterRecord::query()->ofType('supplier')->where('status', 'active')
            ->findOrFail((int) $data['supplier_id']);
        $workspace = (int) $supplier->workspace_id;
        $supplierId = (int) $supplier->id;
        $policy = $access->policy($workspace);
        abort_unless($policy['enabled'], 403, 'Product module access is disabled in FlowTracker Settings.');
        $allowed = static function (string $section, string $action = 'view') use ($policy): bool {
            $actions = $policy['permissions'][$section] ?? [];
            return in_array('view', $actions, true) && ($action === 'view'
                || in_array($action, $actions, true) || in_array('manage', $actions, true));
        };
        $require = static function (string $section, string $action = 'view') use ($allowed): void {
            abort_unless($allowed($section, $action), 403, 'This Product section is not permitted by FlowTracker.');
        };
        $action = $data['action'];
        $group = (string) ($data['group'] ?? 'product');
        $id = (int) ($data['id'] ?? 0);
        $input = (array) ($data['data'] ?? []);
        $mutations = ['save_product', 'delete_product', 'save_category', 'delete_category', 'save_pricing', 'upload_asset'];
        if (in_array($action, $mutations, true)) {
            // Reject signature replays even when the request is otherwise valid.
            abort_unless(Cache::add('supplier-native-product:'.$data['event_id'], true, 600), 409, 'Duplicate operation.');
        }

        $product = fn (int $key) => $this->assignedProduct($workspace, $supplierId, $key);

        $result = match ($action) {
            'catalog' => $this->catalog($workspace, $supplierId, $data, $allowed, $require),
            'lookups' => $this->lookups($workspace, $allowed),
            'detail' => $this->detail($product($id), $supplierId, $allowed, $require),
            'save_product' => $this->saveProduct($workspace, $supplierId, $id, $input, $allowed, $require),
            'delete_product' => $this->deleteProduct($product($id), $supplierId, $require),
            'save_category' => $this->saveCategory($workspace, $id, $input, $require),
            'delete_category' => $this->deleteCategory($workspace, $id, $require),
            'save_pricing' => $this->savePricing($product($id), $supplierId, $input, $allowed, $require),
            'asset' => $this->asset($product($id), $input, $allowed, $require),
            'upload_asset' => $this->uploadAsset($product($id), $input, $require),
        };
        return response()->json(['data' => $result])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function categories(int $workspace): array
    {
        return MasterRecord::query()->forWorkspace($workspace)
            ->whereIn('type', ['product_main_category', 'product_category', 'product_subcategory'])
            ->orderBy('name')->get(['id', 'type', 'name', 'code', 'description', 'status', 'parent_id', 'metadata', 'updated_at'])
            ->map(fn (MasterRecord $r) => [
                'id' => (int) $r->id, 'type' => $r->type, 'name' => $r->name,
                'code' => $r->code, 'description' => $r->description, 'status' => $r->status, 'parent_id' => $r->parent_id,
                'main_category_id' => (int) data_get($r->metadata, 'main_category_id'),
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->all();
    }

    private function catalog(int $workspace, int $supplierId, array $data, callable $allowed, callable $require): array
    {
        $group = $data['group'] ?? 'product';
        $section = $group === 'product' ? 'products' : 'categories';
        $require($section);
        if ($group === 'product_category') return [
            'group' => $group,
            'categories' => $this->categories($workspace),
            'product_counts' => $this->assignedQuery($workspace, $supplierId)
                ->selectRaw('parent_id, COUNT(*) as total')->groupBy('parent_id')
                ->pluck('total', 'parent_id')->all(),
            'permissions' => $this->permissions($allowed),
        ];
        $query = $this->assignedQuery($workspace, $supplierId)->with('parent');
        $q = trim((string) ($data['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($where) => $where->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)->orWhere('metadata->reference_code', 'like', $like));
        }
        if (in_array($data['status'] ?? null, ['active', 'inactive'], true)) {
            $query->where('status', $data['status']);
        }
        if (($data['category_id'] ?? 0) > 0 && $allowed('categories')) {
            $query->where('parent_id', (int) $data['category_id']);
        }
        if (filled($data['main_category'] ?? null) && $allowed('categories')) {
            $query->where('metadata->main_category', (string) $data['main_category']);
        }
        if ($allowed('availability') && in_array($data['availability'] ?? null, ['all', 'specific'], true)) {
            if ($data['availability'] === 'specific') {
                $query->where('metadata->client_availability', 'specific');
            } else {
                $query->where(fn ($w) => $w->whereNull('metadata->client_availability')
                    ->orWhere('metadata->client_availability', 'all'));
            }
        }
        $perPage = (int) ($data['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20;
        $paginator = $query->orderByDesc('id')->paginate($perPage, [
            'id', 'code', 'name', 'status', 'description', 'parent_id', 'metadata', 'created_at', 'updated_at',
        ], 'page', (int) ($data['page'] ?? 1));
        // Source-of-truth fields come from FlowTracker's product model. Never expose
        // attachments or client assignments when the corresponding section is denied.
        $rows = collect($paginator->items())->map(function (MasterRecord $p) use ($allowed): array {
            $docs = $allowed('documents') ? $p->productDocuments() : [];
            return [
                'id' => (int) $p->id,
                'code' => $p->productDisplayCode(),
                'name' => $p->name,
                'status' => $p->status,
                'description' => $p->description,
                'reference_code' => $p->productReferenceCode(),
                'size' => $p->productSize(),
                'category_id' => $p->parent_id,
                'main_category' => $p->productMainCategory(),
                'classification_path' => $p->productClassificationPath(),
                'has_image' => $allowed('documents') && filled(data_get($p->metadata, 'product_image_path')),
                'document_count' => count($docs),
                'first_document_label' => (string) ($docs[0]['label'] ?? ''),
                'availability_labels' => $allowed('availability') ? $p->productAvailabilityLabels() : [],
                'availability_mode' => $allowed('availability') ? (string) data_get($p->metadata, 'client_availability', 'all') : null,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ];
        })->all();
        return [
            'group' => $group, 'products' => $rows, 'total' => $paginator->total(),
            'page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $perPage,
            'categories' => $allowed('categories') ? $this->categories($workspace) : [],
            'permissions' => $this->permissions($allowed),
        ];
    }

    private function lookups(int $workspace, callable $allowed): array
    {
        return [
            'categories' => $allowed('categories') ? $this->categories($workspace) : [],
            'currencies' => MasterRecord::query()->forWorkspace($workspace)->ofType('currency')->active()
                ->orderBy('name')->get(['code', 'name', 'metadata'])
                ->map(fn (MasterRecord $r) => [
                    'code' => app(\App\Services\MasterDataService::class)->currencyValue($r), 'name' => $r->name,
                ])->all(),
            'shipment_urgencies' => $allowed('shipment') ? MasterRecord::query()->forWorkspace($workspace)
                ->ofType('shipment_urgency')->active()->get(['id', 'name', 'code'])->toArray() : [],
            'clients' => ($allowed('availability') || $allowed('client_pricing'))
                ? \App\Models\Client::query()->where('is_active', true)
                    ->orderBy('name')->limit(200)->get(['id', 'name', 'code'])->toArray() : [],
            'permissions' => $this->permissions($allowed),
        ];
    }

    private function permissions(callable $allowed): array
    {
        $output = [];
        foreach (SupplierProductModuleAccess::SECTIONS as $section => $actions) {
            foreach ($actions as $action) $output[$section][$action] = $allowed($section, $action);
        }
        return $output;
    }

    private function assignedQuery(int $workspace, int $supplierId)
    {
        // The normalized supplier-to-product pivot is authoritative. Old metadata
        // must not restore access after FlowTracker removes an assignment.
        return MasterRecord::query()->forWorkspace($workspace)->ofType('product')
            ->whereExists(function ($links) use ($workspace, $supplierId): void {
                $links->selectRaw('1')->from('product_supplier_links')
                    ->whereColumn('product_supplier_links.product_id', 'master_records.id')
                    ->where('product_supplier_links.workspace_id', $workspace)
                    ->where('product_supplier_links.supplier_id', $supplierId);
            });
    }

    private function assignedProduct(int $workspace, int $supplierId, int $id): MasterRecord
    {
        abort_unless($id > 0, 404);
        return $this->assignedQuery($workspace, $supplierId)->findOrFail($id);
    }

    private function detail(MasterRecord $record, int $supplierId, callable $allowed, callable $require): array
    {
        $require('products');
        $metadata = (array) ($record->metadata ?? []);
        $result = [
            'id' => (int) $record->id, 'name' => $record->name,
            'code' => $record->code, 'display_code' => $record->productDisplayCode(),
            'reference_code' => $record->productReferenceCode(),
            'description' => $record->description, 'size' => $record->productSize(),
            'status' => $record->status, 'parent_id' => $record->parent_id,
            'main_category' => $record->productMainCategory(),
            'sub_category' => (string) ($metadata['sub_category'] ?? ''),
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'created_by' => $record->creator?->name,
            'test_certificate_number' => $allowed('documents') ? (string) ($metadata['test_certificate_number'] ?? '') : null,
            'has_image' => $allowed('documents') && filled($metadata['product_image_path'] ?? null),
            'permissions' => $this->permissions($allowed),
        ];
        if ($allowed('availability')) {
            $result['availability'] = [
                'mode' => (string) ($metadata['client_availability'] ?? 'all'),
                'client_ids' => $metadata['client_ids'] ?? [],
                'labels' => $record->productAvailabilityLabels(),
            ];
        }
        if ($allowed('supplier_pricing')) {
            $row = DB::table('supplier_product_prices')->where('workspace_id', $record->workspace_id)
                ->where('product_id', $record->id)->where('supplier_id', $supplierId)
                ->first(['price_breakpoints', 'additional_charges']);
            $pricing = app(SupplierPricing::class);
            $matrix = app(SupplierPriceMatrix::class);
            $prices = $row ? $pricing->normalize(json_decode((string) $row->price_breakpoints, true) ?: []) : [];
            $charges = $row ? $matrix->normalizeCharges(json_decode((string) $row->additional_charges, true) ?: []) : [];
            $result['supplier_pricing'] = [
                'additional_tables' => app(SupplierCurrencyPriceTables::class)->forProduct($record, [$supplierId]),
                'new_table_version' => app(SupplierCurrencyPriceTables::class)->version([], []),
                'currency' => $record->supplierPriceCurrency($supplierId),
                'rows' => $prices, 'charges' => $charges,
                'version' => $pricing->version($prices),
                'charges_version' => $matrix->chargesVersion($charges),
            ];
        }
        if ($allowed('client_pricing')) {
            $result['client_pricing'] = [
                'currency' => $record->clientPriceCurrency(),
                'rows' => $record->customerPriceBreakpoints(),
                'client_rows' => DB::table('product_client_prices')->where('workspace_id', $record->workspace_id)
                    ->where('product_id', $record->id)->limit(200)
                    ->get(['client_id', 'price_breakpoints'])->map(fn ($p) => [
                        'client_id' => (int) $p->client_id,
                        'rows' => json_decode((string) $p->price_breakpoints, true) ?: [],
                        'currency' => $record->clientPriceCurrency((int) $p->client_id),
                        'version' => hash('sha256', (string) $p->price_breakpoints),
                    ])->all(),
            ];
        }
        if ($allowed('shipment')) $result['shipment'] = [
            'remote_surcharge' => $record->productRemoteSurchargeBreakpoints(),
            'urgencies' => $record->productShipmentUrgencyOptions(),
        ];
        if ($allowed('options')) $result['options'] = collect($record->productOptions())->map(function (array $option) use ($record): array {
            $paths = (array) data_get($record->metadata, 'product_options', []);
            $match = collect($paths)->first(fn ($v) => is_array($v) && ($v['key'] ?? '') === $option['key']);
            $option['has_image'] = filled($match['image_path'] ?? null);
            unset($option['image_url']); // Do not leak FlowTracker-origin URLs into Supplier Portal.
            return $option;
        })->all();
        if ($allowed('documents')) $result['documents'] = array_map(static fn ($doc) => [
            'kind' => $doc['kind'], 'label' => $doc['label'],
        ], $record->productDocuments());
        return $result;
    }

    private function saveProduct(int $workspace, int $supplierId, int $id, array $input, callable $allowed, callable $require): array
    {
        $require('products', $id ? 'edit' : 'create');
        if ($id) $this->assignedProduct($workspace, $supplierId, $id);
        $data = validator($input, [
            'name' => ['required', 'string', 'max:240'],
            'reference_code' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:10000'],
            'size' => ['nullable', 'string', 'max:4000'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'sub_category' => ['nullable', 'string', 'max:200'],
            'status' => ['required', 'in:active,inactive'],
        ])->validate();
        $parent = ! empty($data['parent_id']) ? MasterRecord::query()->forWorkspace($workspace)
            ->ofType('product_category')->findOrFail((int) $data['parent_id']) : null;
        $record = DB::transaction(function () use ($workspace, $supplierId, $id, $data, $parent): MasterRecord {
            $record = $id ? MasterRecord::query()->forWorkspace($workspace)->ofType('product')
                ->whereKey($id)->lockForUpdate()->firstOrFail() : new MasterRecord();
            // Preserve all existing metadata/finance/document data. Never accept arbitrary metadata from the API.
            $metadata = (array) ($record->metadata ?? []);
            $metadata['reference_code'] = (string) ($data['reference_code'] ?? '');
            $metadata['product_size'] = (string) ($data['size'] ?? '');
            $metadata['sub_category'] = (string) ($data['sub_category'] ?? '');
            if ($parent) $metadata['main_category'] = (string) data_get($parent->metadata, 'main_category');
            if (! $record->exists) {
                $metadata['supplier_id'] = $supplierId;
                $metadata['supplier_ids'] = [$supplierId];
                $record->workspace_id = $workspace;
                $record->type = 'product';
                $record->code = 'PRD-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 10));
            }
            $record->fill([
                'name' => $data['name'], 'description' => $data['description'] ?? null,
                'parent_id' => $parent?->id, 'metadata' => $metadata, 'status' => $data['status'],
            ]);
            $record->save();
            DB::table('product_supplier_links')->insertOrIgnore([
                'workspace_id' => $workspace, 'product_id' => $record->id,
                'supplier_id' => $supplierId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $record;
        });
        $this->syncLegacy($record);
        $this->reconcile($supplierId);
        return ['id' => (int) $record->id, 'message' => $id ? 'Product updated.' : 'Product created.'];
    }

    private function deleteProduct(MasterRecord $record, int $supplierId, callable $require): array
    {
        $require('products', 'delete');
        // Reuse FlowTracker's existing dependency checks rather than an unsafe bare delete.
        // Deletions are soft deletes. Preserve historical order and inquiry links.
        // FlowTracker's own Master Data delete uses the same soft-delete contract.
        $id = (int) $record->id;
        abort_if(DB::table('product_supplier_links')->where('workspace_id', $record->workspace_id)
            ->where('product_id', $id)->where('supplier_id', '!=', $supplierId)->exists(), 409,
            'This product is also assigned to other suppliers. Ask FlowTracker staff to delete or unlink it.');
        $supplierIds = DB::table('product_supplier_links')->where('product_id', $id)->pluck('supplier_id')->all();
        if (data_get($record->metadata, 'product_image_path')) {
            app(\App\Services\ProductImageService::class)->remove($record);
        }
        if (data_get($record->metadata, 'product_options')) {
            app(\App\Services\ProductOptionImageService::class)->removeAll($record);
        }
        $record->delete();
        $this->syncLegacy($record, true);
        foreach ($supplierIds as $supplierId) $this->reconcile((int) $supplierId);
        return ['message' => 'Product deleted.'];
    }

    private function saveCategory(int $workspace, int $id, array $input, callable $require): array
    {
        $require('categories', $id ? 'edit' : 'create');
        $data = validator($input, [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'type' => ['required', 'in:product_main_category,product_category,product_subcategory'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'in:active,inactive'],
        ])->validate();
        $type = $data['type'];
        $parentType = $type === 'product_subcategory' ? 'product_category'
            : ($type === 'product_category' ? 'product_main_category' : null);
        $parent = $parentType ? MasterRecord::query()->forWorkspace($workspace)->ofType($parentType)
            ->findOrFail((int) ($data['parent_id'] ?? 0)) : null;
        $record = $id ? MasterRecord::query()->forWorkspace($workspace)->where('type', $type)->findOrFail($id) : new MasterRecord();
        $metadata = (array) ($record->metadata ?? []);
        if ($type === 'product_category') {
            $metadata['main_category_id'] = $parent->id;
            $metadata['main_category'] = $parent->name;
            $metadata['excel_main_category'] = $parent->name;
        }
        $record->fill([
            'workspace_id' => $workspace, 'type' => $type,
            'code' => $record->code ?: strtoupper(match ($type) {
                'product_main_category' => 'MCAT', 'product_subcategory' => 'SCAT', default => 'CAT',
            }).'-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 10)),
            'name' => $data['name'], 'description' => $data['description'] ?? null,
            'parent_id' => $type === 'product_subcategory' ? $parent?->id : null,
            'metadata' => $metadata, 'status' => $data['status'],
        ]);
        $record->save();
        $this->syncLegacy($record);
        return ['id' => (int) $record->id, 'message' => 'Product category saved.'];
    }

    private function deleteCategory(int $workspace, int $id, callable $require): array
    {
        $require('categories', 'delete');
        $record = MasterRecord::query()->forWorkspace($workspace)
            ->whereIn('type', ['product_main_category', 'product_category', 'product_subcategory'])->findOrFail($id);
        abort_if(MasterRecord::query()->forWorkspace($workspace)->where('parent_id', $id)->exists(), 409,
            'This category has child records or products. Reassign them first.');
        if ($record->type === 'product_main_category') {
            abort_if(MasterRecord::query()->forWorkspace($workspace)->ofType('product_category')
                ->where('metadata->main_category_id', $id)->exists(), 409, 'This main category is in use.');
        }
        $record->delete();
        $this->syncLegacy($record, true);
        return ['message' => 'Product category deleted.'];
    }

    private function savePricing(MasterRecord $record, int $supplierId, array $input, callable $allowed, callable $require): array
    {
        $section = (string) ($input['section'] ?? '');
        abort_unless(in_array($section, ['supplier_pricing', 'supplier_currency_table', 'client_pricing', 'client_override', 'shipment', 'options', 'availability'], true), 422);
        $require(match ($section) {
            'client_override' => 'client_pricing',
            'supplier_currency_table' => 'supplier_pricing',
            default => $section,
        }, 'edit');
        $workspace = (int) $record->workspace_id;
        if ($section === 'client_override') return $this->saveClientOverride($record, $input);
        if ($section === 'supplier_currency_table') {
            $data = validator($input, [
                'section' => ['required', 'in:supplier_currency_table'],
                'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
                'base_version' => ['required', 'regex:/^[a-f0-9]{64}$/'],
                'tiers' => ['sometimes', 'array', 'max:100'],
                'remove' => ['sometimes', 'boolean'],
            ])->validate();
            app(SupplierCurrencyPriceTables::class)->save(
                $record, $supplierId, $data['currency'], $data['tiers'] ?? [],
                $data['base_version'], (bool) ($data['remove'] ?? false)
            );
            $this->reconcile($supplierId);
            return ['message' => !empty($data['remove']) ? 'Currency table removed.' : 'Currency price table saved.'];
        }
        if ($section === 'supplier_pricing') {
            $data = validator($input, [
                'section' => ['required', 'in:supplier_pricing'], 'tiers' => ['required', 'array', 'max:100'],
                'base_version' => ['required', 'regex:/^[a-f0-9]{64}$/'],
                'charges_version' => ['required', 'regex:/^[a-f0-9]{64}$/'],
                'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            ])->validate();
            $matrix = app(SupplierPriceMatrix::class);
            $parsed = $matrix->parseEditableTiers($data['tiers']);
            $pricing = app(SupplierPricing::class);
            DB::transaction(function () use ($record, $supplierId, $workspace, $parsed, $data, $matrix, $pricing): void {
                // Both primary and extra-currency edits lock the product first, so
                // neither can switch into the other's currency during a race.
                MasterRecord::query()->forWorkspace($workspace)->ofType('product')
                    ->whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_if(DB::table('supplier_product_currency_tables')
                    ->where('workspace_id', $workspace)->where('product_id', $record->id)
                    ->where('supplier_id', $supplierId)->where('currency', $data['currency'])->exists(), 422,
                    'This currency already has an additional supplier price table. Remove it before changing the primary currency.');
                $existing = DB::table('supplier_product_prices')->where('workspace_id', $workspace)
                    ->where('supplier_id', $supplierId)->where('product_id', $record->id)->first();
                $oldPrices = $existing ? $pricing->normalize(json_decode((string) $existing->price_breakpoints, true) ?: []) : [];
                $oldCharges = $existing ? $matrix->normalizeCharges(json_decode((string) $existing->additional_charges, true) ?: []) : [];
                abort_unless(hash_equals($pricing->version($oldPrices), $data['base_version'])
                    && hash_equals($matrix->chargesVersion($oldCharges), $data['charges_version']), 409,
                    'Pricing was updated elsewhere. Reload before saving.');
                DB::table('supplier_product_prices')->upsert([[
                    'workspace_id' => $workspace, 'supplier_id' => $supplierId, 'product_id' => $record->id,
                    'price_breakpoints' => json_encode($parsed['rows'], JSON_THROW_ON_ERROR),
                    'additional_charges' => json_encode($parsed['charges'], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]], ['supplier_id', 'product_id'], ['price_breakpoints', 'additional_charges', 'updated_at']);
                if ($oldPrices !== $parsed['rows']) DB::table('supplier_price_proposals')
                    ->where('workspace_id', $workspace)->where('supplier_id', $supplierId)
                    ->where('product_id', $record->id)->where('status', 'pending')
                    ->update(['status' => 'stale', 'review_note' => 'Supplier Product module changed the approved price.', 'updated_at' => now()]);
                $row = MasterRecord::query()->whereKey($record->id)->firstOrFail();
                $metadata = (array) $row->metadata;
                $currencies = (array) ($metadata['supplier_price_currencies'] ?? []);
                $currencies[$supplierId] = $data['currency'];
                $metadata['supplier_price_currencies'] = $currencies;
                $row->metadata = $metadata;
                $row->save();
            });
            $this->reconcile($supplierId);
            return ['message' => 'Supplier pricing saved.'];
        }
        $updated = DB::transaction(function () use ($record, $section, $input, $workspace): array {
            $row = MasterRecord::query()->forWorkspace($workspace)->ofType('product')
                ->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) ($row->metadata ?? []);
            if ($section === 'client_pricing') {
                $data = validator($input, [
                    'tiers' => ['required', 'array', 'max:100'],
                    'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
                ])->validate();
                try { $prices = app(ClientPriceMatrix::class)->parseEditableTiers($data['tiers']); }
                catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['tiers' => $e->getMessage()]); }
                $metadata['customer_price_currency'] = $data['currency'];
                $metadata['customer_price_breakpoints'] = $prices;
                $metadata['customer_price_table_raw'] = app(ClientPriceMatrix::class)->toLegacyTable($prices);
            } elseif ($section === 'shipment') {
                $data = validator($input, [
                    'remote_surcharge' => ['array', 'max:100'],
                    'urgencies' => ['array', 'max:30'],
                    'urgencies.*.shipment_urgency_id' => ['required', 'integer', 'min:1'],
                    'urgencies.*.extra_charge' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
                ])->validate();
                try { $prices = app(ClientPriceMatrix::class)->parseEditableTiers($data['remote_surcharge'] ?? []); }
                catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['remote_surcharge' => $e->getMessage()]); }
                $metadata['remote_surcharge_breakpoints'] = $prices;
                $urgencies = [];
                foreach ($data['urgencies'] ?? [] as $urgency) {
                    $entry = MasterRecord::query()->forWorkspace($workspace)->ofType('shipment_urgency')
                        ->findOrFail($urgency['shipment_urgency_id']);
                    $urgencies[] = [
                        'key' => (string) Str::uuid(), 'shipment_urgency_id' => (int) $entry->id,
                        'shipment_urgency_name' => $entry->name, 'shipment_urgency_code' => $entry->code,
                        'extra_charge' => (float) $urgency['extra_charge'],
                    ];
                }
                $metadata['shipment_urgency_options'] = $urgencies;
            } elseif ($section === 'options') {
                $data = validator($input, [
                    'options' => ['array', 'max:50'], 'options.*.label' => ['required', 'string', 'max:140'],
                    'options.*.extra_charge' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
                ])->validate();
                $existing = collect((array) ($metadata['product_options'] ?? []))->keyBy('key');
                $metadata['product_options'] = collect($data['options'] ?? [])->map(function ($option) use ($existing) {
                    $key = (string) ($option['key'] ?? '');
                    $prior = $existing->get($key);
                    return ['key' => $key !== '' && $prior ? $key : (string) Str::uuid(),
                        'label' => $option['label'], 'extra_charge' => (float) $option['extra_charge'],
                        'image_path' => $prior['image_path'] ?? null];
                })->all();
            } elseif ($section === 'availability') {
                $data = validator($input, [
                    'mode' => ['required', 'in:all,specific'], 'client_ids' => ['array', 'max:200'],
                    'client_ids.*' => ['integer', 'min:1'],
                ])->validate();
                $ids = $data['mode'] === 'specific' ? array_values(array_unique(array_map('intval', $data['client_ids'] ?? []))) : [];
                $clients = $ids === [] ? collect() : \App\Models\Client::query()->whereIn('id', $ids)->get(['id', 'name', 'code']);
                abort_unless($clients->count() === count($ids), 422);
                $metadata['client_availability'] = $data['mode'];
                $metadata['client_ids'] = $ids;
                $metadata['client_availability_labels'] = $clients->pluck('name')->all();
                $metadata['client_codes'] = $clients->pluck('code')->all();
            }
            $row->metadata = $metadata;
            $row->save();
            return ['message' => 'Product '.str_replace('_', ' ', $section).' saved.'];
        });
        $this->syncLegacy($record->fresh());
        $this->reconcileAssigned($record);
        return $updated;
    }

    /** Client-specific overrides remain separate from the global client price table. */
    private function saveClientOverride(MasterRecord $record, array $input): array
    {
        $data = validator($input, [
            'section' => ['required', 'in:client_override'],
            'client_id' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'tiers' => ['required', 'array', 'max:100'],
            'base_version' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'remove' => ['nullable', 'boolean'],
        ])->validate();
        $clientId = (int) $data['client_id'];
        abort_unless(\App\Models\Client::query()->where('is_active', true)->whereKey($clientId)->exists(), 422,
            'Select an active client.');
        try { $prices = app(ClientPriceMatrix::class)->parseEditableTiers($data['tiers']); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['tiers' => $e->getMessage()]); }
        DB::transaction(function () use ($record, $clientId, $data, $prices): void {
            $product = MasterRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('product_client_prices')->where('workspace_id', $product->workspace_id)
                ->where('product_id', $product->id)->where('client_id', $clientId);
            $old = $query->first(['price_breakpoints']);
            $oldVersion = $old ? hash('sha256', (string) $old->price_breakpoints) : null;
            abort_unless(($data['base_version'] ?? null) === $oldVersion, 409,
                'Client prices have changed since you opened the product. Reload before saving.');
            $metadata = (array) ($product->metadata ?? []);
            $currencies = (array) ($metadata['client_price_currencies'] ?? []);
            if (! empty($data['remove'])) {
                $query->delete();
                unset($currencies[$clientId]);
            } else {
                DB::table('product_client_prices')->upsert([[
                    'workspace_id' => $product->workspace_id, 'product_id' => $product->id,
                    'client_id' => $clientId,
                    'price_breakpoints' => json_encode($prices, JSON_THROW_ON_ERROR),
                    'price_table_raw' => app(ClientPriceMatrix::class)->toLegacyTable($prices),
                    'created_at' => now(), 'updated_at' => now(),
                ]], ['product_id', 'client_id'], ['price_breakpoints', 'price_table_raw', 'updated_at']);
                $currencies[$clientId] = $data['currency'];
            }
            $metadata['client_price_currencies'] = $currencies;
            $product->metadata = $metadata;
            $product->save();
        });
        $this->syncLegacy($record->fresh());
        $this->reconcileAssigned($record);
        return ['message' => ! empty($data['remove']) ? 'Client price override removed.' : 'Client price override saved.'];
    }

    private function asset(MasterRecord $record, array $input, callable $allowed, callable $require): array
    {
        $kind = (string) ($input['kind'] ?? 'image');
        $require($kind === 'option_image' ? 'options' : 'documents');
        $metadata = (array) $record->metadata;
        $key = match ($kind) {
            'option_image' => null,
            'image' => 'product_image_path',
            'certificate' => 'certificate_test_report_path',
            'template' => 'template_doc_path',
            default => abort(404),
        };
        $optionKey = (string) ($input['option_key'] ?? '');
        if ($kind === 'option_image') {
            $option = collect((array) ($metadata['product_options'] ?? []))
                ->first(fn ($o) => is_array($o) && ($o['key'] ?? null) === $optionKey);
            $path = trim((string) ($option['image_path'] ?? ''));
        } else {
            $path = trim((string) ($metadata[$key] ?? ''));
        }
        abort_unless($path !== '' && ! str_contains($path, '..'), 404);
        if ($kind === 'image' || $kind === 'option_image') {
            $expectedFolder = $kind === 'image' ? "product-images/{$record->workspace_id}/{$record->id}/"
                : "product-option-images/{$record->workspace_id}/{$record->id}/{$optionKey}/";
            abort_unless(str_starts_with($path, $expectedFolder), 404);
            $disk = Storage::disk('public');
        } else {
            abort_unless(str_starts_with($path, "product-documents/{$record->workspace_id}/{$record->id}/"), 404);
            $location = app(\App\Services\SecureDocumentStorage::class)->locate($path);
            abort_unless($location !== null, 404);
            $disk = Storage::disk($location['disk']);
        }
        abort_unless($disk->exists($path) && $disk->size($path) <= 6500000, 404);
        $content = $disk->get($path);
        return ['name' => basename($path), 'content' => base64_encode($content),
            'content_type' => in_array($kind, ['image','option_image'], true) ? match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg',
            } : 'application/octet-stream'];
    }

    private function uploadAsset(MasterRecord $record, array $input, callable $require): array
    {
        $kind = (string) ($input['kind'] ?? '');
        $require($kind === 'option_image' ? 'options' : 'documents', 'edit');
        $data = validator($input, [
            'kind' => ['required', 'in:image,certificate,template,option_image'],
            'option_key' => ['required_if:kind,option_image', 'nullable', 'regex:/^[A-Za-z0-9-]{8,80}$/'],
            'filename' => ['required', 'string', 'max:180'],
            'content' => ['required', 'string', 'max:7100000'],
        ])->validate();
        $binary = base64_decode($data['content'], true);
        abort_unless($binary !== false && strlen($binary) <= 5 * 1024 * 1024, 413);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        $kind = $data['kind'];
        if (in_array($kind, ['image', 'option_image'], true)) abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 422);
        else abort_unless(in_array($mime, ['application/pdf', 'image/png', 'image/jpeg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true), 422);
        $extension = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
            'application/pdf' => 'pdf', default => 'docx',
        };
        $tmp = tempnam(sys_get_temp_dir(), 'supplier-native-upload-');
        abort_unless($tmp !== false, 503);
        try {
            file_put_contents($tmp, $binary);
            $file = new \Illuminate\Http\UploadedFile(
                $tmp, basename($data['filename']), $mime, null, true
            );
            if ($kind === 'image') {
                app(\App\Services\ProductImageService::class)->replace($record, $file);
            } elseif ($kind === 'option_image') {
                $rows = array_values((array) data_get($record->metadata, 'product_options', []));
                $index = null;
                foreach ($rows as $key => $row) {
                    if (is_array($row) && ($row['key'] ?? null) === $data['option_key']) { $index = $key; break; }
                }
                abort_unless($index !== null, 404, 'Unknown product option.');
                app(\App\Services\ProductOptionImageService::class)->sync($record, $rows, [$index => $file]);
            } else {
                app(\App\Services\ProductDocumentService::class)->sync(
                    $record,
                    $kind === 'certificate' ? $file : null,
                    $kind === 'template' ? $file : null
                );
            }
        } finally {
            @unlink($tmp);
        }
        $this->syncLegacy($record->fresh());
        $this->reconcileAssigned($record);
        return ['message' => ucfirst($kind).' uploaded.'];
    }

    /** Preserve FlowTracker's historical Master Value mirror and active-category cache. */
    private function syncLegacy(MasterRecord $record, bool $deleted = false): void
    {
        $group = match ($record->type) {
            'product' => 'products', 'product_category' => 'product_categories',
            'product_main_category' => 'product_main_categories',
            'product_subcategory' => 'product_subcategories',
            default => null,
        };
        if ($group === null) return;
        if (Schema::hasTable('master_values')) {
            if ($deleted) {
                MasterValue::query()->where('group_key', $group)->where('code', $record->code)->delete();
            } else {
                $parentId = null;
                if ($record->type === 'product' && $record->parent_id) {
                    $parent = MasterRecord::query()->forWorkspace((int) $record->workspace_id)
                        ->ofType('product_category')->find($record->parent_id);
                    $parentId = $parent ? MasterValue::query()->where('group_key', 'product_categories')
                        ->where('code', $parent->code)->value('id') : null;
                }
                MasterValue::query()->updateOrCreate(
                    ['group_key' => $group, 'code' => $record->code],
                    ['name' => $record->name, 'description' => $record->description,
                        'parent_id' => $parentId, 'is_active' => $record->status === 'active',
                        'meta' => $record->metadata ?: null]
                );
            }
        }
        Cache::forget("flowtrack:master:active:{$record->workspace_id}:{$record->type}");
    }

    private function reconcileAssigned(MasterRecord $record): void
    {
        $ids = DB::table('product_supplier_links')->where('workspace_id', $record->workspace_id)
            ->where('product_id', $record->id)->pluck('supplier_id')->all();
        $ids[] = (int) data_get($record->metadata, 'supplier_id');
        foreach (array_unique(array_map('intval', $ids)) as $id) $this->reconcile($id);
    }

    private function reconcile(int $supplierId): void
    {
        if ($supplierId <= 0 || ! config('supplier_bridge.enabled')) return;
        // Never fail accepted database writes because the other service is temporarily unavailable.
        try { app(\App\Services\SupplierBridge\SendCatalogImmediately::class)->send($supplierId); }
        catch (\Throwable $exception) {
            report($exception);
            // The existing scheduler still reconciles the catalogue.
        }
    }
}
