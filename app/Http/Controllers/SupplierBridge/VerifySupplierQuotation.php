<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\InquiryRfqInvitation;
use Illuminate\Http\Request;

/** Reuses the existing bridge secret but reverses the direction and header prefix. */
trait VerifySupplierQuotation
{
    private function bridgeSecret(): ?string
    {
        $secret = (string) config('supplier_bridge.secret');
        return config('supplier_bridge.enabled') && strlen($secret) >= 32 ? $secret : null;
    }

    private function signedQuotation(Request $request): array
    {
        $secret = $this->bridgeSecret();
        $body = $request->getContent();
        $timestamp = $request->header('X-Supplier-Timestamp');
        $event = $request->header('X-Supplier-Event');
        $signature = $request->header('X-Supplier-Signature');
        abort_unless($secret && strlen($body) <= 1048576 && is_string($timestamp) && ctype_digit($timestamp) &&
            abs(time() - (int)$timestamp) <= 300 && is_string($event) &&
            preg_match('/^[0-9a-f-]{36}$/i',$event) && is_string($signature) &&
            preg_match('/^[0-9a-f]{64}$/i',$signature) &&
            hash_equals(hash_hmac('sha256',$timestamp."\n".$event."\n".$body,$secret),$signature),401);
        $decoded = json_decode($body,true);
        abort_unless(is_array($decoded) && ($decoded['event_id'] ?? null) === $event, 401);
        return $decoded;
    }

    private function authorizeInvitation(int $id, int $supplierId): InquiryRfqInvitation
    {
        $invitation = InquiryRfqInvitation::query()->whereKey($id)->where('supplier_id',$supplierId)
            ->whereNotNull('invited_at')->where('email_status','!=','Draft')->firstOrFail();
        abort_if($invitation->awarded_at || $invitation->rejected_at, 409, 'RFQ is closed.');
        return $invitation;
    }
}
