<?php

namespace App\Support\Performance;

use Illuminate\Http\Request;
use Throwable;

/**
 * Opt-in Phase 1 diagnostic. Emits one bound, append-only JSON line per event.
 * Never records SQL bindings, authentication headers, request bodies or file names.
 */
final class OrderBaselineRecorder
{
    public static function enabled(): bool
    {
        return (bool) config('order_baseline.enabled', false);
    }

    /** @return array<string, mixed>|null */
    public static function httpContext(Request $request): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        if ($request->isMethod('GET') && $request->is('orders') && $request->integer('open') > 0) {
            return ['kind' => 'initial_get', 'order_id' => $request->integer('open')];
        }

        // Livewire may use a versioned/hashed update URI. Match the named
        // route registered by Livewire, retaining the literal-path fallback
        // for tests and conventional update URLs.
        $isLivewireUpdate = $request->route()?->getName() === 'default-livewire.update'
            || $request->is('livewire/update');
        if (! $request->isMethod('POST') || ! $isLivewireUpdate) {
            return null;
        }

        $components = $request->input('components');
        if (! is_array($components)) {
            return null;
        }

        $matched = [];
        foreach (array_slice($components, 0, 20) as $component) {
            if (! is_array($component)) {
                continue;
            }
            $snapshot = $component['snapshot'] ?? null;
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }
            if (! is_array($snapshot)) {
                continue;
            }
            $name = (string) ($snapshot['memo']['name'] ?? '');
            if ($name !== 'jobs.index' && ! str_starts_with($name, 'jobs.order-')) {
                continue;
            }
            $data = (array) ($snapshot['data'] ?? []);
            // Extract only scalar IDs; do not log the serialized Livewire state.
            $orderId = $data['orderId'] ?? $data['selectedJobId'] ?? null;
            if (! is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId))) {
                $orderId = null;
            }
            $methods = [];
            foreach (array_slice((array) ($component['calls'] ?? []), 0, 15) as $call) {
                if (! is_array($call)) continue;
                $method = (string) ($call['method'] ?? '');
                if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,100}$/', $method)) $methods[] = $method;
            }
            $matched[] = [
                'component' => substr($name, 0, 80),
                'order_id' => $orderId ? (int) $orderId : null,
                'methods' => $methods,
            ];
        }

        return $matched === [] ? null : [
            'kind' => 'livewire_update',
            'order_id' => count(array_unique(array_column($matched, 'order_id'))) === 1 ? $matched[0]['order_id'] : null,
            'components' => $matched,
        ];
    }

    /** @param array<string, mixed> $fields */
    public static function record(string $kind, array $fields = []): void
    {
        if (! self::enabled()) return;

        // Invalid run labels cannot affect the storage path or escape log formatting.
        $runId = (string) config('order_baseline.run_id', 'baseline');
        if (! preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $runId)) $runId = 'baseline';

        $entry = array_merge([
            'timestamp' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) ((microtime(true) * 1000) % 1000)),
            'run_id' => $runId,
            'kind' => $kind,
        ], $fields);

        try {
            $json = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            // Protect business operations if telemetry storage is unavailable.
            @file_put_contents(storage_path('logs/order-details-baseline.jsonl'), $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Baseline collection must never change an order/task outcome.
        }
    }
}
