<?php

namespace App\Support\Performance;

use Closure;
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
            // Parent jobs.index also mounts in create mode. Do not record
            // unrelated creates/list updates when the order ID is absent.
            if ($name === 'jobs.index' && (!$orderId || (int) $orderId < 1)) {
                continue;
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

    /**
     * Process-local PHP CPU time (user + system). This is not DB or total wall time.
     * getrusage() is optional on PHP builds; unavailable results remain null.
     */
    public static function processCpuMs(): ?float
    {
        if (! function_exists('getrusage')) {
            return null;
        }

        $usage = getrusage();
        if (! is_array($usage)) {
            return null;
        }
        foreach (['ru_utime.tv_sec', 'ru_utime.tv_usec', 'ru_stime.tv_sec', 'ru_stime.tv_usec'] as $key) {
            if (! isset($usage[$key])) {
                return null;
            }
        }

        return 1000 * ((float) $usage['ru_utime.tv_sec'] + (float) $usage['ru_stime.tv_sec'])
            + ((float) $usage['ru_utime.tv_usec'] + (float) $usage['ru_stime.tv_usec']) / 1000;
    }

    /**
     * A tightly scoped diagnostic wrapper. It returns exactly what the original
     * callback returned and never swallows exceptions from business operations.
     * No measurement is written unless the opt-in flag and Order Details HTTP
     * context are both present. Only static, known span labels are accepted.
     *
     * @template T
     * @param Closure():T $operation
     * @return T
     */
    public static function measure(string $span, Closure $operation, int $orderId): mixed
    {
        if (! self::enabled() || ! in_array($span, [
            'remote_area_match', 'workflow_sync', 'artwork_repair',
            'workflow_auto_advance', 'workflow_detail_context', 'workflow_load_relations',
        ], true)) {
            return $operation();
        }

        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request || self::httpContext($request) === null) {
            return $operation();
        }

        $monitor = app()->resolved(RequestPerformanceMonitor::class)
            ? app(RequestPerformanceMonitor::class) : null;
        $queryBefore = $monitor?->baselineQueryTotals();
        $startedAt = hrtime(true);
        $startedCpu = self::processCpuMs();
        $completed = false;
        try {
            $result = $operation();
            $completed = true;
            return $result;
        } finally {
            $cpu = self::processCpuMs();
            $queryAfter = $monitor?->baselineQueryTotals();
            self::record('service_span', [
                'span' => $span,
                'request_id' => $monitor?->baselineRequestId(),
                'order_id' => $orderId > 0 ? $orderId : null,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1000000, 2),
                'db_query_count' => $queryBefore !== null && $queryAfter !== null
                    ? max(0, $queryAfter['count'] - $queryBefore['count']) : null,
                'db_time_ms' => $queryBefore !== null && $queryAfter !== null
                    ? round(max(0.0, $queryAfter['time_ms'] - $queryBefore['time_ms']), 2) : null,
                'php_cpu_ms' => $startedCpu !== null && $cpu !== null
                    ? round(max(0.0, $cpu - $startedCpu), 2) : null,
                'success' => $completed,
            ]);
        }
    }

    /** @param array<string, mixed> $fields */
    public static function record(string $kind, array $fields = []): void
    {
        if (! self::enabled()) return;

        // Invalid run labels cannot affect the storage path or escape log formatting.
        $runId = (string) config('order_baseline.run_id', 'baseline');
        if (! preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $runId)) $runId = 'baseline';

        // The event type must not be overwritten by request-context subtype.
        // Older baseline files did this accidentally; the report supports both.
        $entry = array_merge($fields, [
            'timestamp' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) ((microtime(true) * 1000) % 1000)),
            'run_id' => $runId,
            'kind' => $kind,
        ]);

        try {
            $json = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            // Protect business operations if telemetry storage is unavailable.
            @file_put_contents(storage_path('logs/order-details-baseline.jsonl'), $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Baseline collection must never change an order/task outcome.
        }
    }
}
