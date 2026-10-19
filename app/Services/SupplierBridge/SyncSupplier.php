<?php

namespace App\Services\SupplierBridge;

use App\Models\MasterRecord;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Supplier identity and approved product catalogue only: never client/customer data. */
final class SyncSupplier
{
    public function __construct(private readonly PortalClient $portal) {}

    public function sync(int $supplierId): bool
    {
        $supplier = MasterRecord::query()->ofType('supplier')->findOrFail($supplierId);
        $id = (int) $supplier->id;
        $workspace = (int) $supplier->workspace_id;
        $metadata = (array) ($supplier->metadata ?? []);
        $reverseIds = collect(data_get($metadata, 'product_ids', data_get($metadata, 'assigned_product_ids', [])))
            ->filter(fn ($value) => is_numeric($value) && (int) $value > 0)->map(fn ($value) => (int) $value)->values()->all();
        $reverseCodes = data_get($metadata, 'product_codes', data_get($metadata, 'assigned_product_codes', []));
        $reverseCodes = collect(is_array($reverseCodes) ? $reverseCodes : preg_split('/[\s,;|]+/', (string) $reverseCodes, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($code) => strtoupper(trim((string) $code)))->filter()->values()->all();
        $hasLinks = Schema::hasTable('product_supplier_links');
        $products = MasterRecord::query()->forWorkspace($workspace)->ofType('product')
            ->where(function ($query) use ($id, $workspace, $hasLinks, $reverseIds, $reverseCodes): void {
                // The normalized tag table is authoritative once installed.
                if ($hasLinks) {
                    $query->whereExists(fn ($links) => $links->selectRaw('1')->from('product_supplier_links')
                        ->whereColumn('product_supplier_links.product_id', 'master_records.id')
                        ->where('product_supplier_links.workspace_id', $workspace)
                        ->where('product_supplier_links.supplier_id', $id));
                    return;
                }
                // Legacy fallback only for pre-migration databases.
                $query->where('metadata->supplier_id', $id)
                    ->orWhere('metadata->default_supplier_id', $id)
                    ->orWhereJsonContains('metadata->supplier_ids', $id);
                if ($reverseIds !== []) $query->orWhereIn('id', $reverseIds);
                if ($reverseCodes !== []) {
                    $query->orWhereIn('code', $reverseCodes);
                    $displayIds = collect($reverseCodes)->map(function ($code) {
                        return preg_match('/^PRD[-\s]*0*(\d+)$/i', $code, $match) ? (int) $match[1] : null;
                    })->filter()->all();
                    if ($displayIds !== []) $query->orWhereIn('id', $displayIds);
                }
            })
            ->orderBy('id')->limit(2001)->get(['id', 'type', 'code', 'name', 'status', 'metadata']);
        if ($products->count() > 2000) {
            throw new RuntimeException('Supplier has more than 2,000 products; catalogue sync was not truncated.');
        }
        // One read for all supplier-specific overrides; never query per product.
        $overrides = DB::table('supplier_product_prices')->where('workspace_id', $workspace)
            ->where('supplier_id', $id)->whereIn('product_id', $products->pluck('id'))
            ->pluck('price_breakpoints', 'product_id');
        $pricing = app(SupplierPricing::class);
        // Review outcomes travel with the next catalogue reconciliation, including rejections.
        $decisions = DB::table('supplier_price_proposals')->where('supplier_id', $id)
            ->whereIn('status', ['approved', 'rejected', 'stale'])->orderByDesc('id')->limit(500)
            ->get(['request_uuid', 'status', 'review_note'])->map(fn ($row) => [
                'request_uuid' => $row->request_uuid, 'status' => $row->status,
                'review_note' => $row->review_note,
            ])->all();
        // The Supplier Portal header must match the logo and company name
        // actually displayed by FlowTracker's own branding settings. Supplier
        // records can belong to another workspace without owning its branding.
        $branding = app(\App\Services\BrandingService::class)->current();
        $response = $this->portal->post('suppliers', [
            'brand_name' => (string) ($branding['name'] ?: 'FlowTrack'),
            'brand_logo_path' => $branding['logo_url'] ?: null,
            'brand_favicon_path' => $branding['favicon_url'] ?: null,
            'footer' => $branding['supplier_portal_footer'],
            'supplier_id' => $id,
            'catalog_revision' => (int) now()->format('Uu'),
            'reference' => 'FLOWTRACK-'.$id,
            'name' => (string) $supplier->name,
            'short_code' => $supplier->supplierShortCode(),
            'active' => $supplier->status === 'active',
            'products' => $products->map(function ($product) use ($overrides, $pricing): array {
                $raw = $overrides->get($product->id);
                // Supplier-specific approved prices only. Never substitute the
                // product-level/default table (or customer/client selling prices).
                $rows = $raw !== null ? $pricing->normalize(json_decode((string) $raw, true) ?: []) : [];
                return [
                    'id' => (int) $product->id,
                    'code' => $product->productDisplayCode(),
                    'name' => (string) $product->name,
                    'active' => $product->status === 'active',
                    'supplier_prices' => $rows,
                    'price_version' => $pricing->version($rows),
                    // Only a stable fingerprint, never the protected source path or bytes.
                    'image_version' => ($path = trim((string) data_get($product->metadata, 'product_image_path'))) !== ''
                        && preg_match('/\.(png|jpe?g|webp)$/i', $path) ? hash('sha256', $path) : null,
                    'image_extension' => preg_match('/\.(png|jpe?g|webp)$/i', $path, $extension)
                        ? strtolower($extension[1]) : null,
                ];
            })->all(),
            'decisions' => $decisions,
        ]);
        if (! $response->successful()) {
            $fields = $response->status() === 422 ? $response->json('invalid_fields', []) : [];
            $detail = is_array($fields) && $fields !== []
                ? ' Invalid fields: '.implode(', ', array_slice(array_filter($fields, 'is_string'), 0, 10)).'.'
                : '';
            throw new RuntimeException('Supplier catalogue API returned HTTP '.$response->status().'.'.$detail);
        }
        return $products->isNotEmpty();
    }

    /** Secrets are transmitted only to the isolated portal over the signed API. */
    public function provision(int $supplierId, string $username, string $email, ?string $password, string $contact, bool $requirePasswordChange = false, bool $syncCatalog = true): void
    {
        if ($syncCatalog && ! $this->sync($supplierId)) {
            throw new RuntimeException('Assign at least one product before creating supplier portal access.');
        }
        $payload = [
            'supplier_id' => $supplierId,
            'username' => $username,
            'email' => $email,
            'name' => $contact,
        ];
        if ($password !== null && $password !== '') {
            $payload['password'] = $password;
            $payload['require_password_change'] = $requirePasswordChange;
        }
        $response = $this->portal->post('supplier-access', $payload);
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                409 => 'Username or email already belongs to another portal account, or an initial password is required.',
                default => 'Supplier account API returned HTTP '.$response->status().'.',
            });
        }
    }

    public function sendPasswordReset(int $supplierId): void
    {
        $response = $this->portal->post('supplier-access/reset', ['supplier_id' => $supplierId]);
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                429 => 'A reset link was recently requested. Please wait before retrying.',
                default => 'The supplier portal could not send the password reset email.',
            });
        }
    }
}
