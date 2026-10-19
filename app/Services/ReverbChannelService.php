<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FlowTrack's small Pusher-protocol adapter for Laravel Reverb.
 *
 * Reverb speaks the Pusher Channels protocol, so keeping this adapter lets the
 * existing realtime queue jobs and browser event names stay unchanged while
 * removing the dependency on Pusher Cloud. The actual WebSocket/API host is
 * always the configured Reverb server.
 */
class ReverbChannelService
{
    public function enabled(): bool
    {
        return (bool) config('services.realtime.enabled', true)
            && filled($this->appId())
            && filled($this->key())
            && filled($this->secret())
            && filled($this->host());
    }

    public function userChannel(int $userId): string
    {
        return 'private-flowtrack.user.'.$userId;
    }

    public function workspaceChannel(int $workspaceId): string
    {
        return 'private-flowtrack.workspace.'.max(1, $workspaceId);
    }

    public function authenticate(string $socketId, string $channelName, int $userId): array
    {
        abort_unless($this->enabled(), 404);

        $workspaceId = max(1, (int) config('flowtrack.workspace_id', 1));
        abort_unless(in_array($channelName, [
            $this->userChannel($userId),
            $this->workspaceChannel($workspaceId),
        ], true), 403);
        abort_unless(preg_match('/^\d+\.\d+$/', $socketId) === 1, 422, 'Invalid socket ID.');

        $signature = hash_hmac('sha256', $socketId.':'.$channelName, $this->secret());

        return ['auth' => $this->key().':'.$signature];
    }

    public function triggerUser(int $userId, string $event, array $payload): void
    {
        $this->triggerChannels([$this->userChannel($userId)], $event, $payload);
    }

    public function triggerWorkspace(int $workspaceId, string $event, array $payload): void
    {
        $this->triggerChannels([$this->workspaceChannel($workspaceId)], $event, $payload);
    }

    public function authenticateSupplierOrder(
        string $socketId, string $channelName, int $supplierId, int $orderId,
    ): array {
        abort_unless($this->enabled(), 503);
        $expected = 'private-flowtrack.supplier.'.$supplierId.'.order.'.$orderId;
        abort_unless($supplierId > 0 && $orderId > 0 && hash_equals($expected, $channelName), 403);
        abort_unless(preg_match('/^\d+\.\d+$/', $socketId), 422);
        return ['auth' => $this->key().':'.hash_hmac(
            'sha256', $socketId.':'.$expected, $this->secret(),
        )];
    }

    /** Supplier-specific private channel; payload is only a refresh hint. */
    public function triggerSupplierOrder(int $supplierId, int $orderId): void
    {
        if ($supplierId < 1 || $orderId < 1) return;
        $this->triggerChannels([
            'private-flowtrack.supplier.'.$supplierId.'.order.'.$orderId,
        ], 'flowtrack.supplier.order.updated', ['changed' => true]);
    }

    public function authenticateSupplierCatalog(string $socketId, string $channelName, int $supplierId): array
    {
        abort_unless($this->enabled(), 503);
        $expected = 'private-flowtrack.supplier.'.$supplierId.'.catalog';
        abort_unless($supplierId > 0 && hash_equals($expected, $channelName), 403);
        abort_unless(preg_match('/^\d+\.\d+$/', $socketId), 422);
        return ['auth' => $this->key().':'.hash_hmac('sha256', $socketId.':'.$expected, $this->secret())];
    }

    /** Notify only this supplier; the event never contains prices or customer data. */
    public function triggerSupplierCatalog(int $supplierId): void
    {
        if ($supplierId < 1) return;
        $this->triggerChannels(['private-flowtrack.supplier.'.$supplierId.'.catalog'],
            'flowtrack.supplier.catalog.updated', ['changed' => true]);
    }

    /** One supplier-scoped change hint, never includes buyer or customer data. */
    public function triggerSupplierDashboard(int $supplierId): void
    {
        if ($supplierId < 1) return;
        $this->triggerChannels(['private-flowtrack.supplier.'.$supplierId.'.dashboard'],
            'flowtrack.supplier.dashboard.updated', ['changed' => true]);
    }

    public function authenticateSupplierDashboard(string $socketId, string $channelName, int $supplierId): array
    {
        abort_unless($this->enabled(), 503);
        $expected = 'private-flowtrack.supplier.'.$supplierId.'.dashboard';
        abort_unless($supplierId > 0 && hash_equals($expected, $channelName), 403);
        abort_unless(preg_match('/^\d+\.\d+$/', $socketId), 422);
        return ['auth' => $this->key().':'.hash_hmac('sha256', $socketId.':'.$expected, $this->secret())];
    }

    private function triggerChannels(array $channels, string $event, array $payload): void
    {
        if (! $this->enabled() || Cache::get($this->circuitKey())) {
            return;
        }

        $channels = collect($channels)
            ->map(fn ($channel) => trim((string) $channel))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($channels === []) {
            return;
        }

        $pathPrefix = trim((string) config('reverb.servers.reverb.path', ''), '/');
        $path = ($pathPrefix !== '' ? '/'.$pathPrefix : '').'/apps/'.$this->appId().'/events';

        $body = json_encode([
            'name' => $event,
            'channels' => $channels,
            'data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new RuntimeException('Unable to encode Reverb payload.');
        }

        $params = [
            'auth_key' => $this->key(),
            'auth_timestamp' => time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];

        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $params['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n{$query}", $this->secret());
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $scheme = $this->scheme();
        $port = $this->port();
        $url = $scheme.'://'.$this->host().(in_array($port, [80, 443], true) ? '' : ':'.$port).$path.'?'.$query;

        $response = Http::connectTimeout((float) config('services.realtime.connect_timeout', 2))
            ->timeout((float) config('services.realtime.timeout', 5))
            ->withBody($body, 'application/json')
            ->post($url);

        if ($response->failed()) {
            $seconds = max(10, (int) config('services.realtime.circuit_seconds', 60));
            Cache::put($this->circuitKey(), true, now()->addSeconds($seconds));

            throw new RuntimeException('Reverb rejected the event with HTTP '.$response->status().'. Realtime delivery is temporarily disabled.');
        }

        Cache::forget($this->circuitKey());
    }

    private function appConfig(): array
    {
        return (array) data_get(config('reverb'), 'apps.apps.0', []);
    }

    private function appId(): string
    {
        return (string) data_get($this->appConfig(), 'app_id', '');
    }

    private function key(): string
    {
        return (string) data_get($this->appConfig(), 'key', '');
    }

    private function secret(): string
    {
        return (string) data_get($this->appConfig(), 'secret', '');
    }

    private function host(): string
    {
        return (string) config('services.realtime.api_host', data_get($this->appConfig(), 'options.host', ''));
    }

    private function port(): int
    {
        $scheme = $this->scheme();

        return (int) (config('services.realtime.api_port') ?: data_get($this->appConfig(), 'options.port') ?: ($scheme === 'https' ? 443 : 80));
    }

    private function scheme(): string
    {
        return strtolower((string) config('services.realtime.api_scheme', data_get($this->appConfig(), 'options.scheme', 'https'))) === 'http' ? 'http' : 'https';
    }

    private function circuitKey(): string
    {
        $fingerprint = implode('|', [
            $this->appId(),
            $this->key(),
            $this->host(),
            (string) $this->port(),
            $this->scheme(),
        ]);

        return 'flowtrack:reverb:circuit-open:'.sha1($fingerprint);
    }
}
