<?php

namespace App\Http\Controllers\SupplierBridge;

use Illuminate\Http\Request;

/** A distinct API key signs each Product module request; the browser never receives it. */
trait VerifySupplierProductApi
{
    private function signedProductApi(Request $request, int $maxBodyBytes = 1048576): array
    {
        $key = (string) config('supplier_bridge.product_api_key');
        $body = $request->getContent();
        $timestamp = $request->header('X-Supplier-Product-Timestamp');
        $event = $request->header('X-Supplier-Product-Event');
        $signature = $request->header('X-Supplier-Product-Signature');

        abort_unless(config('supplier_bridge.enabled') && strlen($key) >= 32
            && strlen($body) <= $maxBodyBytes
            && is_string($timestamp) && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 300
            && is_string($event) && preg_match('/^[0-9a-f-]{36}$/i', $event)
            && is_string($signature) && preg_match('/^[0-9a-f]{64}$/i', $signature)
            && hash_equals(hash_hmac('sha256', $timestamp."\n".$event."\n".$body, $key), $signature), 401);

        $decoded = json_decode($body, true);
        abort_unless(is_array($decoded) && ($decoded['event_id'] ?? null) === $event, 401);

        return $decoded;
    }
}
