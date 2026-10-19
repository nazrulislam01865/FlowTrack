<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\MasterRecord;
use App\Services\SupplierBridge\SupplierPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Supplier requests never write an active price. They only create a review item. */
final class ReceiveSupplierPriceProposal extends Controller
{
    use VerifySupplierQuotation;

    public function __invoke(Request $request, SupplierPricing $pricing): JsonResponse
    {
        $data = $this->signedQuotation($request);
        $validated = Validator::make($data, [
            'event_id' => ['required', 'uuid'],
            'request_uuid' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'base_version' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'prices' => ['required', 'array', 'min:1', 'max:100'],
            'prices.*.quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'prices.*.price' => ['required', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,6'],
        ])->validate();
        $supplier = MasterRecord::query()->ofType('supplier')->findOrFail((int) $validated['supplier_id']);
        abort_unless($supplier->status === 'active', 403);
        $product = MasterRecord::query()->forWorkspace($supplier->workspace_id)->ofType('product')
            ->findOrFail((int) $validated['product_id']);
        abort_unless($product->status === 'active' && $pricing->assigned($product, (int) $supplier->id), 403);
        $rows = $pricing->normalize($validated['prices']);

        $result = DB::transaction(function () use ($data, $supplier, $product, $pricing, $rows): array {
            $existing = DB::table('supplier_price_proposals')->where('request_uuid', $data['request_uuid'])->first();
            if ($existing) {
                abort_unless((int) $existing->supplier_id === (int) $supplier->id &&
                    (int) $existing->product_id === (int) $product->id, 409);
                abort_unless($existing->base_version === $data['base_version'] &&
                    $existing->proposed_prices === json_encode($rows, JSON_THROW_ON_ERROR), 409);
                return ['status' => $existing->status, 'created' => false];
            }
            // Lock the product to serialize simultaneous price proposals/approvals.
            MasterRecord::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $version = $pricing->version($pricing->rows($product, (int) $supplier->id));
            abort_unless(hash_equals($version, $data['base_version']), 409, 'Price has changed. Refresh the catalogue before submitting.');
            if (DB::table('supplier_price_proposals')->where('supplier_id', $supplier->id)
                ->where('product_id', $product->id)->where('status', 'pending')->exists()) {
                abort(409, 'A price change for this product already awaits approval.');
            }
            DB::table('supplier_price_proposals')->insert([
                'request_uuid' => $data['request_uuid'], 'workspace_id' => $supplier->workspace_id,
                'product_id' => $product->id, 'supplier_id' => $supplier->id,
                'base_version' => $version, 'proposed_prices' => json_encode($rows, JSON_THROW_ON_ERROR),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => 'pending', 'created' => true];
        });

        if ($result['created']) {
            // Reuse FlowTracker's existing notification bell and unread counter.
            // Fail-open on notification delivery; the persistent approval request remains available.
            try {
                $admins = \App\Models\User::query()->where('is_super_admin', true)->where('is_active', true)
                    ->limit(50)->get(['id', 'is_active']);
                foreach ($admins as $admin) {
                    app(\App\Services\NotificationService::class)->notifyUser(
                        $admin, 'Supplier price approval required',
                        $supplier->name.' requested a supplier price change for '.$product->name.'. Open Supplier Price Approvals to review.',
                        'info', null, null, null, false,
                    );
                }
            } catch (\Throwable $exception) { report($exception); }
        }
        return response()->json(['received' => true, 'status' => $result['status']]);
    }
}
