<?php

namespace App\Services\SupplierBridge;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Signed, outbound-only requests to the isolated supplier application. */
final class PortalClient
{
    public function post(string $path, array $payload): Response
    {
        $url = (string) config('supplier_bridge.url');
        $secret = (string) config('supplier_bridge.secret');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (! config('supplier_bridge.enabled') || strlen($secret) < 32 ||
            ! in_array($path, ['suppliers', 'supplier-access', 'supplier-access/reset', 'rfqs', 'branding'], true) ||
            ! is_string($host) || $host === '' || str_contains($url, '@') ||
            str_contains($url, '?') || str_contains($url, '#') ||
            ! ($scheme === 'https' || ($scheme === 'http' && app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true)))) {
            throw new RuntimeException('Supplier portal API is not securely configured.');
        }

        $event = (string) Str::uuid();
        $payload['event_id'] = $event;
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        return Http::connectTimeout(2)->timeout(8)->withHeaders([
            'X-FlowTrack-Timestamp' => $time,
            'X-FlowTrack-Event' => $event,
            'X-FlowTrack-Signature' => hash_hmac('sha256', $time."\n".$event."\n".$body, $secret),
            'Accept' => 'application/json',
        ])->withoutRedirecting()->withBody($body, 'application/json')
            ->post($url.'/api/v1/integrations/flowtrack/'.$path);
    }
}
