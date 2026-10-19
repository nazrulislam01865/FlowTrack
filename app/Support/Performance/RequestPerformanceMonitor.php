<?php

namespace App\Support\Performance;

use App\Services\Observability\OperationsMetrics;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RequestPerformanceMonitor
{
    public function __construct(private readonly OperationsMetrics $operationsMetrics) {}
    private float $startedAt = 0.0;
    private int $queryCount = 0;
    private float $queryTimeMs = 0.0;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private int $cacheWrites = 0;
    private int $cacheForgets = 0;
    private int $cacheFailovers = 0;
    private array $slowQueries = [];
    private array $queryFingerprints = [];
    private array $outgoingRequests = [];
    private array $outgoingStartedAt = [];
    private bool $sampled = false;
    private bool $activeRequest = false;
    private ?array $orderBaselineContext = null;
    private array $orderBaselineShapes = [];

    public function start(Request $request): void
    {
        $this->reset();
        $this->orderBaselineContext = OrderBaselineRecorder::httpContext($request);
        $this->sampled = $this->shouldSample() || $this->orderBaselineContext !== null;
        $this->activeRequest = true;
        $this->startedAt = microtime(true);
    }

    public function recordQuery(QueryExecuted $query): void
    {
        if (!$this->sampled) return;

        $this->queryCount++;
        $this->queryTimeMs += (float) $query->time;
        if ($this->orderBaselineContext !== null) {
            // Hash SQL templates. Bindings/values and raw SQL stay out of files.
            $key = substr(hash('sha256', $query->sql), 0, 16);
            $shape = $this->orderBaselineShapes[$key] ?? ['hash' => $key, 'count' => 0, 'time_ms' => 0.0];
            $shape['count']++;
            $shape['time_ms'] += (float) $query->time;
            $this->orderBaselineShapes[$key] = $shape;
        }

        if (config('performance.query_fingerprints', false)) {
            $fingerprint = preg_replace('/\s+/', ' ', trim($query->sql)) ?: trim($query->sql);
            $row = $this->queryFingerprints[$fingerprint] ?? ['count' => 0, 'time_ms' => 0.0];
            $row['count']++;
            $row['time_ms'] += (float) $query->time;
            $this->queryFingerprints[$fingerprint] = $row;
        }

        $threshold = (int) config('performance.slow_query_ms', 150);
        if ($query->time < $threshold || count($this->slowQueries) >= 10) return;

        $row = [
            'connection' => $query->connectionName,
            'time_ms' => round((float) $query->time, 2),
        ];

        if (config('performance.include_query_sql', false)) {
            $row['sql'] = $query->sql;
        }

        $this->slowQueries[] = $row;
    }

    public function recordCacheHit(): void
    {
        if ($this->sampled) $this->cacheHits++;
    }

    public function recordCacheMiss(): void
    {
        if ($this->sampled) $this->cacheMisses++;
    }

    public function recordCacheWrite(): void
    {
        if ($this->sampled) $this->cacheWrites++;
    }

    public function recordCacheForget(): void
    {
        if ($this->sampled) $this->cacheForgets++;
    }

    public function recordCacheFailover(): void
    {
        if ($this->sampled) $this->cacheFailovers++;
    }

    public function startOutgoing(object $request): void
    {
        if (!config('performance.enabled', true)) return;

        // Queue workers do not pass through the HTTP middleware. Sample their
        // outgoing requests independently so Reverb and other integrations are
        // still observable without polluting normal request metrics.
        if (!$this->activeRequest && !$this->sampled) {
            $this->sampled = $this->shouldSample();
        }
        if (!$this->sampled) return;

        $this->outgoingStartedAt[spl_object_id($request)] = microtime(true);
    }

    public function finishOutgoing(object $request, ?object $response = null, ?Throwable $error = null): void
    {
        if (!$this->sampled) return;

        $id = spl_object_id($request);
        $startedAt = $this->outgoingStartedAt[$id] ?? microtime(true);
        unset($this->outgoingStartedAt[$id]);

        $stats = $response && method_exists($response, 'handlerStats')
            ? (array) $response->handlerStats()
            : [];
        $durationMs = isset($stats['total_time'])
            ? ((float) $stats['total_time'] * 1000)
            : ((microtime(true) - $startedAt) * 1000);

        $method = method_exists($request, 'method') ? (string) $request->method() : 'GET';
        $url = method_exists($request, 'url') ? (string) $request->url() : '';
        $status = $response && method_exists($response, 'status') ? (int) $response->status() : null;

        $this->recordOutgoing(
            parse_url($url, PHP_URL_HOST) ?: 'http',
            $method,
            $url,
            $durationMs,
            $status,
            $error?->getMessage(),
        );
    }

    public function recordOutgoing(
        string $service,
        string $method,
        string $url,
        float $durationMs,
        ?int $status = null,
        ?string $error = null,
    ): void {
        if (!$this->sampled) return;

        $row = array_filter([
            'service' => $service,
            'method' => strtoupper($method),
            'host' => parse_url($url, PHP_URL_HOST),
            'duration_ms' => round($durationMs, 2),
            'status' => $status,
            'error' => $error,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($this->activeRequest) {
            if (count($this->outgoingRequests) < 10) $this->outgoingRequests[] = $row;
            return;
        }

        $slow = $durationMs >= (int) config('performance.slow_outgoing_ms', 500);
        $failed = $error !== null || ($status !== null && $status >= 400);
        if ($slow || $failed) {
            Log::warning('FlowTrack outgoing request performance', $row);
        }
    }

    public function finish(Request $request, Response $response): void
    {
        if (!$this->sampled || $this->startedAt <= 0) return;

        $durationMs = (microtime(true) - $this->startedAt) * 1000;
        $nonDbMs = max(0.0, $durationMs - $this->queryTimeMs);
        $payload = $this->payload($request, $response->getStatusCode(), $durationMs);

        if (config('performance.server_timing', false)) {
            $response->headers->set('Server-Timing', sprintf(
                'app;dur=%.2f, db;dur=%.2f;desc="%d queries", non_db;dur=%.2f',
                $durationMs,
                $this->queryTimeMs,
                $this->queryCount,
                $nonDbMs,
            ));
        }

        if ($this->orderBaselineContext !== null) {
            $shapes = array_values($this->orderBaselineShapes);
            usort($shapes, static fn (array $a, array $b): int => $b['time_ms'] <=> $a['time_ms']);
            $shapes = array_slice($shapes, 0, max(1, min(50, (int) config('order_baseline.max_query_shapes', 15))));
            OrderBaselineRecorder::record('http_request', array_merge($this->orderBaselineContext, [
                'status' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 2),
                'db_query_count' => $this->queryCount,
                'db_time_ms' => round($this->queryTimeMs, 2),
                'non_db_ms' => round(max(0.0, $durationMs - $this->queryTimeMs), 2),
                'peak_php_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'request_bytes' => max(0, (int) $request->header('Content-Length', 0)),
                'response_bytes' => $response->headers->has('Content-Length')
                    ? (int) $response->headers->get('Content-Length') : null,
                'user_id' => $request->user()?->id,
                'top_query_shapes' => $shapes,
            ]));
        }

        $this->writeRequestLog($durationMs, $payload);
        $this->operationsMetrics->recordRequest($payload);
        $this->activeRequest = false;
    }

    public function finishException(Request $request, Throwable $exception): void
    {
        if (!$this->sampled || $this->startedAt <= 0) return;

        $durationMs = (microtime(true) - $this->startedAt) * 1000;
        $payload = $this->payload($request, 500, $durationMs);
        $payload['exception'] = $exception::class;
        $payload['exception_message'] = $exception->getMessage();

        if ($this->orderBaselineContext !== null) {
            OrderBaselineRecorder::record('http_request', array_merge($this->orderBaselineContext, [
                'status' => 500,
                'exception' => $exception::class,
                'duration_ms' => round($durationMs, 2),
                'db_query_count' => $this->queryCount,
                'db_time_ms' => round($this->queryTimeMs, 2),
                'peak_php_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]));
        }
        Log::warning('FlowTrack failed request performance', $payload);
        $this->operationsMetrics->recordRequest($payload, true);
        $this->activeRequest = false;
    }

    private function payload(Request $request, int $status, float $durationMs): array
    {
        $payload = [
            'method' => $request->method(),
            'route' => $request->route()?->getName() ?: $request->path(),
            'status' => $status,
            'duration_ms' => round($durationMs, 2),
            'queries' => $this->queryCount,
            'query_time_ms' => round($this->queryTimeMs, 2),
            'non_db_ms' => round(max(0.0, $durationMs - $this->queryTimeMs), 2),
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_writes' => $this->cacheWrites,
            'cache_forgets' => $this->cacheForgets,
            'cache_failovers' => $this->cacheFailovers,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'user_id' => $request->user()?->id,
        ];

        $livewireCalls = $this->livewireCalls($request);
        if ($livewireCalls) $payload['livewire_calls'] = $livewireCalls;
        if ($this->slowQueries) $payload['slow_queries'] = $this->slowQueries;
        if ($this->queryFingerprints) {
            $fingerprints = [];
            foreach ($this->queryFingerprints as $sql => $stats) {
                if (($stats['count'] ?? 0) < 2) continue;
                $fingerprints[] = [
                    'count' => (int) $stats['count'],
                    'time_ms' => round((float) $stats['time_ms'], 2),
                    'sql' => $sql,
                ];
            }
            usort($fingerprints, static fn (array $a, array $b): int =>
                [$b['count'], $b['time_ms']] <=> [$a['count'], $a['time_ms']]
            );
            if ($fingerprints) $payload['repeated_queries'] = array_slice($fingerprints, 0, 10);
        }
        if ($this->outgoingRequests) $payload['outgoing_requests'] = $this->outgoingRequests;

        return $payload;
    }

    private function livewireCalls(Request $request): array
    {
        if (($request->route()?->getName() ?: '') !== 'default-livewire.update') return [];

        $components = $request->input('components');
        if (!is_array($components)) return [];

        $calls = [];
        foreach (array_slice($components, 0, 12) as $component) {
            if (!is_array($component)) continue;

            $componentName = 'livewire';
            $snapshot = $component['snapshot'] ?? null;
            if (is_string($snapshot) && $snapshot !== '') {
                $decoded = json_decode($snapshot, true);
                if (is_array($decoded)) $snapshot = $decoded;
            }
            if (is_array($snapshot)) {
                $memoName = $snapshot['memo']['name'] ?? null;
                if (is_string($memoName) && $memoName !== '') $componentName = $memoName;
            }

            foreach (array_slice((array) ($component['calls'] ?? []), 0, 12) as $call) {
                if (!is_array($call)) continue;
                $method = trim((string) ($call['method'] ?? ''));
                if ($method === '') continue;

                // Method parameters can contain user data, so only log the
                // component and method name. That is enough to attribute slow
                // Livewire requests without increasing log sensitivity.
                $calls[] = $componentName.'::'.$method;
                if (count($calls) >= 12) break 2;
            }
        }

        return array_values(array_unique($calls));
    }

    private function writeRequestLog(float $durationMs, array $payload): void
    {
        if (config('performance.log_all_requests', false)
            || $durationMs >= (int) config('performance.slow_request_ms', 750)
            || $this->queryTimeMs >= (int) config('performance.slow_query_total_ms', 400)) {
            Log::warning('FlowTrack request performance', $payload);
        }
    }

    private function shouldSample(): bool
    {
        $rate = max(0.0, min(1.0, (float) config('performance.sample_rate', 1.0)));

        return config('performance.enabled', true)
            && ($rate >= 1.0 || (mt_rand() / mt_getrandmax()) <= $rate);
    }

    private function reset(): void
    {
        $this->queryCount = 0;
        $this->queryTimeMs = 0.0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheWrites = 0;
        $this->cacheForgets = 0;
        $this->cacheFailovers = 0;
        $this->slowQueries = [];
        $this->queryFingerprints = [];
        $this->outgoingRequests = [];
        $this->outgoingStartedAt = [];
        $this->sampled = false;
        $this->activeRequest = false;
        $this->orderBaselineContext = null;
        $this->orderBaselineShapes = [];
    }
}
