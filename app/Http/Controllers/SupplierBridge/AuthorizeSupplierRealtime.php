<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Services\ReverbChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Signed machine-only channel authorization. Reverb secret stays in FlowTracker. */
final class AuthorizeSupplierRealtime extends Controller
{
    public function __invoke(Request $request, ReverbChannelService $reverb): JsonResponse
    {
        $secret = (string) config('supplier_bridge.secret');
        $time = (string) $request->header('X-Supplier-Timestamp');
        $event = (string) $request->header('X-Supplier-Event');
        $signature = (string) $request->header('X-Supplier-Signature');
        $body = $request->getContent();
        abort_unless(config('supplier_bridge.enabled') && strlen($secret) >= 32
            && strlen($body) > 0 && strlen($body) <= 2048
            && ctype_digit($time) && abs(time() - (int) $time) <= 300
            && preg_match('/^[0-9a-f-]{36}$/i', $event)
            && preg_match('/^[a-f0-9]{64}$/i', $signature)
            && hash_equals(hash_hmac('sha256', $time."\n".$event."\n".$body, $secret), $signature), 401);

        $data = Validator::make(json_decode($body, true) ?: [], [
            'event_id' => ['required', 'uuid', 'in:'.$event],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'resource' => ['sometimes', 'in:catalog,dashboard'],
            'order_id' => ['required_without:resource', 'nullable', 'integer', 'min:1'],
            'socket_id' => ['required', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:150'],
        ])->validate();
        $supplierId = (int) $data['supplier_id'];
        if (($data['resource'] ?? null) === 'catalog') {
            abort_unless(\App\Models\MasterRecord::query()->ofType('supplier')
                ->whereKey($supplierId)->where('status', 'active')->exists(), 403);
            return response()->json($reverb->authenticateSupplierCatalog(
                (string) $data['socket_id'], (string) $data['channel_name'], $supplierId,
            ))->header('Cache-Control', 'private, no-store');
        }
        if (($data['resource'] ?? null) === 'dashboard') {
            abort_unless(\App\Models\MasterRecord::query()->ofType('supplier')
                ->whereKey($supplierId)->where('status', 'active')->exists(), 403);
            return response()->json($reverb->authenticateSupplierDashboard(
                (string) $data['socket_id'], (string) $data['channel_name'], $supplierId,
            ))->header('Cache-Control', 'private, no-store');
        }
        $orderId = (int) $data['order_id'];
        // Even a valid bridge signature cannot subscribe an order/supplier pair
        // that does not have a live assigned product and an existing delivery.
        abort_unless(DB::table('supplier_order_deliveries')
            ->where('flow_job_id', $orderId)->where('supplier_id', $supplierId)
            ->exists(), 403);
        abort_unless(DB::table('flow_job_items')
            ->where('flow_job_id', $orderId)->where('supplier_id', $supplierId)
            ->where('is_removed', false)->where('quantity', '>', 0)->exists(), 403);
        return response()->json($reverb->authenticateSupplierOrder(
            (string) $data['socket_id'], (string) $data['channel_name'],
            $supplierId, $orderId,
        ))->header('Cache-Control', 'private, no-store');
    }
}
