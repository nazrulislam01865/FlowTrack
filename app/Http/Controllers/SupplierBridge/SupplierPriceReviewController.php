<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\MasterRecord;
use App\Services\SupplierBridge\SupplierPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** Administrator-only review, with optimistic base-price protection and audit. */
final class SupplierPriceReviewController extends Controller
{
    public function index()
    {
        $workspaceId = app(\App\Services\MasterDataService::class)->workspaceId();
        // Only actual supplier-submitted changes; never render unrelated assignments.
        $proposals = DB::table('supplier_price_proposals as proposals')
            ->join('master_records as suppliers', 'suppliers.id', '=', 'proposals.supplier_id')
            ->join('master_records as products', 'products.id', '=', 'proposals.product_id')
            ->leftJoin('supplier_product_prices as prices', function ($join): void {
                $join->on('prices.product_id', '=', 'proposals.product_id')
                    ->on('prices.supplier_id', '=', 'proposals.supplier_id');
            })
            ->where('proposals.workspace_id', $workspaceId)
            ->where('proposals.status', 'pending')
            ->select('proposals.*', 'suppliers.name as supplier_name',
                'products.name as product_name', 'products.metadata as product_metadata',
                'prices.price_breakpoints as override_prices')
            ->orderBy('proposals.created_at')->orderBy('proposals.id')
            ->paginate(20);

        $pricing = app(SupplierPricing::class);
        $proposals->getCollection()->transform(static function ($proposal) use ($pricing) {
            $product = new MasterRecord();
            $product->setRawAttributes(['type' => 'product', 'metadata' => $proposal->product_metadata]);
            $old = $proposal->override_prices !== null
                ? json_decode((string) $proposal->override_prices, true) ?: []
                : $product->supplierPriceBreakpoints();
            $old = $pricing->normalize($old);
            $new = $pricing->normalize(json_decode((string) $proposal->proposed_prices, true) ?: []);
            $oldByQuantity = collect($old)->keyBy('quantity');
            $newByQuantity = collect($new)->keyBy('quantity');
            $quantities = $oldByQuantity->keys()
                ->merge($newByQuantity->keys())
                ->unique()
                ->sort(SORT_NUMERIC)
                ->values();
            $proposal->changes = $quantities->map(static function ($quantity) use ($oldByQuantity, $newByQuantity) {
                $previous = $oldByQuantity->get($quantity);
                $requested = $newByQuantity->get($quantity);
                return [
                    'quantity' => (int) $quantity,
                    'old_price' => $previous['price'] ?? null,
                    'new_price' => $requested['price'] ?? null,
                    'changed' => ($previous['price'] ?? null) !== ($requested['price'] ?? null),
                ];
            })->values()->all();
            $proposal->new_tier_count = count($new);
            $proposal->proposed_rows = $new;
            $proposal->product_display_code = 'PRD-'.str_pad((string) $proposal->product_id, 6, '0', STR_PAD_LEFT);
            return $proposal;
        });

        return view('pages.supplier-price-approvals', compact('proposals'));
    }

    /** Direct FlowTracker edits to a supplier-specific cost are authoritative immediately. */
    public function updatePrice(Request $request, int $supplier, int $product, SupplierPricing $pricing): RedirectResponse
    {
        $validated = $request->validate(['prices' => ['required', 'string', 'max:10000']]);
        $rows = [];
        foreach (preg_split('/\R/', trim($validated['prices'])) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (! preg_match('/^(\d+)[\t ,;|]+(\d+(?:\.\d{1,6})?)$/', $line, $parts)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['prices' => 'Use one quantity and unit price per row.']);
            }
            $rows[] = ['quantity' => (int) $parts[1], 'price' => (float) $parts[2]];
        }
        if ($rows === []) throw \Illuminate\Validation\ValidationException::withMessages(['prices' => 'Enter at least one price tier.']);
        $rows = $pricing->normalize($rows);
        $workspaceId = app(\App\Services\MasterDataService::class)->workspaceId();
        DB::transaction(function () use ($supplier, $product, $workspaceId, $pricing, $rows): void {
            $record = MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')
                ->whereKey($product)->lockForUpdate()->firstOrFail();
            abort_unless(MasterRecord::query()->forWorkspace($workspaceId)->ofType('supplier')
                ->whereKey($supplier)->exists() && $pricing->assigned($record, $supplier), 403);
            DB::table('supplier_product_prices')->upsert([[
                'workspace_id' => $workspaceId, 'product_id' => $product, 'supplier_id' => $supplier,
                'price_breakpoints' => json_encode($rows, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['supplier_id', 'product_id'], ['price_breakpoints', 'updated_at']);
            DB::table('supplier_price_proposals')->where('supplier_id', $supplier)
                ->where('product_id', $product)->where('status', 'pending')->update([
                    'status' => 'stale', 'review_note' => 'FlowTracker updated this supplier price directly.',
                    'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'updated_at' => now(),
                ]);
            app(\App\Services\SupplierBridge\MarkCatalogDirty::class)->supplierIds($workspaceId, [$supplier]);
        });
        return back()->with('success', 'Supplier price saved. Secure portal delivery has been queued.');
    }

    public function review(Request $request, int $proposal, SupplierPricing $pricing): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
        $result = DB::transaction(function () use ($proposal, $data, $pricing): array {
            // Lock product first, then proposal: same order as direct edits and API intake.
            $reference = DB::table('supplier_price_proposals')->where('id', $proposal)->first(['product_id', 'workspace_id']);
            abort_unless($reference && (int) $reference->workspace_id === (int) app(\App\Services\MasterDataService::class)->workspaceId(), 404);
            $product = MasterRecord::query()->forWorkspace((int) $reference->workspace_id)
                ->ofType('product')->whereKey($reference->product_id)->lockForUpdate()->first();
            $pending = DB::table('supplier_price_proposals')->where('id', $proposal)->lockForUpdate()->first();
            abort_unless($pending && $pending->status === 'pending', 409, 'Request has already been reviewed.');
            $supplier = MasterRecord::query()->forWorkspace((int) $pending->workspace_id)
                ->ofType('supplier')->find($pending->supplier_id);
            $valid = $product && $supplier && $supplier->status === 'active' && $product->status === 'active'
                && $pricing->assigned($product, (int) $pending->supplier_id);
            $current = $valid ? $pricing->version($pricing->rows($product, (int) $pending->supplier_id)) : null;
            $decision = $data['decision'];
            if ($decision === 'approved' && (! $valid || ! hash_equals((string) $pending->base_version, (string) $current))) {
                $decision = 'stale'; // Never overwrite a newer authoritative price.
            }
            if ($decision === 'approved') {
                DB::table('supplier_product_prices')->upsert([[
                    'workspace_id' => $pending->workspace_id, 'supplier_id' => $pending->supplier_id,
                    'product_id' => $pending->product_id, 'price_breakpoints' => $pending->proposed_prices,
                    'created_at' => now(), 'updated_at' => now(),
                ]], ['supplier_id', 'product_id'], ['price_breakpoints', 'updated_at']);
            }
            DB::table('supplier_price_proposals')->where('id', $pending->id)->update([
                'status' => $decision, 'reviewed_by' => auth()->id(), 'reviewed_at' => now(),
                'review_note' => $decision === 'stale' ? 'Price or supplier assignment changed; resubmit the updated table.' : ($data['review_note'] ?? null),
                'updated_at' => now(),
            ]);
            app(\App\Services\SupplierBridge\MarkCatalogDirty::class)->supplierIds(
                (int) $pending->workspace_id, [(int) $pending->supplier_id],
            );
            return ['supplier_id' => (int) $pending->supplier_id, 'status' => $decision];
        });
        // MarkCatalogDirty persisted the request and queued an after-commit delivery.
        // Never wait on supplier network availability in the administrator's browser.
        $message = match ($result['status']) {
            'approved' => 'Supplier prices approved. The supplier portal will update shortly.',
            'rejected' => 'Price request rejected. Supplier notification is queued.',
            default => 'Request is stale. Supplier notification is queued.',
        };
        return back()->with('success', $message);
    }
}
