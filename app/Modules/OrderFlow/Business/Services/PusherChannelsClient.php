<?php

namespace App\Modules\OrderFlow\Business\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PusherChannelsClient
{
    public function enabled(): bool
    {
        return filled(config('services.pusher.app_id'))
            && filled(config('services.pusher.key'))
            && filled(config('services.pusher.secret'));
    }

    /**
     * Trigger an event without requiring the optional pusher PHP SDK.
     * This keeps the project deployable with the existing Composer lock while
     * still using the official Pusher Channels HTTP protocol and Pusher JS.
     *
     * @param array<string, mixed> $payload
     */
    public function trigger(string|array $channels, string $event, array $payload): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $channels = collect((array) $channels)
            ->filter()
            ->map(fn (string $channel): string => trim($channel))
            ->unique()
            ->values()
            ->all();

        if ($channels === []) {
            return false;
        }

        $body = json_encode([
            'name' => $event,
            'channels' => $channels,
            'data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($body === false) {
            throw new RuntimeException('Unable to encode the Pusher event payload.');
        }

        $path = '/apps/'.rawurlencode((string) config('services.pusher.app_id')).'/events';
        $parameters = [
            'auth_key' => (string) config('services.pusher.key'),
            'auth_timestamp' => time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($parameters);

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$query}", (string) config('services.pusher.secret'));
        $url = $this->baseUrl().$path.'?'.$query.'&auth_signature='.$signature;

        try {
            Http::timeout(5)
                ->connectTimeout(3)
                ->withBody($body, 'application/json')
                ->post($url)
                ->throw();

            return true;
        } catch (Throwable $exception) {
            // Realtime delivery must never make a Job, Task, document, or phase
            // transaction fail. The database notification remains the source of truth.
            Log::warning('FlowTrack Pusher delivery failed.', [
                'event' => $event,
                'channels' => $channels,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function authenticatePrivateChannel(string $socketId, string $channelName): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Pusher is not configured.');
        }

        $signature = hash_hmac(
            'sha256',
            $socketId.':'.$channelName,
            (string) config('services.pusher.secret'),
        );

        return [
            'auth' => (string) config('services.pusher.key').':'.$signature,
        ];
    }

    private function baseUrl(): string
    {
        $scheme = (string) config('services.pusher.scheme', 'https');
        $host = (string) config('services.pusher.host');
        $cluster = (string) config('services.pusher.cluster', 'ap2');
        $port = (int) config('services.pusher.port', $scheme === 'https' ? 443 : 80);

        if ($host === '') {
            $host = "api-{$cluster}.pusher.com";
        }

        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.$host.($defaultPort ? '' : ':'.$port);
    }
}
