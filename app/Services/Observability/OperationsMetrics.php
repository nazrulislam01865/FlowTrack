<?php

namespace App\Services\Observability;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class OperationsMetrics
{
    private bool $reportedFailure = false;

    public function recordRequest(array $payload, bool $failed = false): void
    {
        if (! $this->enabled()) return;

        $this->safely(function (Connection $redis) use ($payload, $failed): void {
            $bucket = $this->bucket();
            $hash = $this->key('minute:'.$bucket);
            $ttl = $this->ttlSeconds();
            $duration = (float) ($payload['duration_ms'] ?? 0);
            $queryTime = (float) ($payload['query_time_ms'] ?? 0);
            $memory = (float) ($payload['memory_peak_mb'] ?? 0);
            $status = (int) ($payload['status'] ?? ($failed ? 500 : 200));
            $isError = $failed || $status >= 500;
            $slowQueries = is_array($payload['slow_queries'] ?? null) ? count($payload['slow_queries']) : 0;

            $redis->hincrby($hash, 'requests', 1);
            if ($isError) $redis->hincrby($hash, 'errors', 1);
            $redis->hincrby($hash, 'slow_queries', $slowQueries);
            $redis->hincrby($hash, 'cache_hits', (int) ($payload['cache_hits'] ?? 0));
            $redis->hincrby($hash, 'cache_misses', (int) ($payload['cache_misses'] ?? 0));
            $redis->expire($hash, $ttl);

            $this->sample($redis, 'duration:'.$bucket, $duration, $ttl);
            $this->sample($redis, 'query:'.$bucket, $queryTime, $ttl);
            $this->sample($redis, 'memory:'.$bucket, $memory, $ttl);
        });
    }

    public function recordQueueDelay(string $job, float $delaySeconds): void
    {
        if (! $this->enabled()) return;
        $this->safely(function (Connection $redis) use ($job, $delaySeconds): void {
            $bucket = $this->bucket();
            $ttl = $this->ttlSeconds();
            $this->sample($redis, 'queue-delay:'.$bucket, $delaySeconds, $ttl);
            $hash = $this->key('minute:'.$bucket);
            $redis->hincrby($hash, 'queue_starts', 1);
            if ($delaySeconds >= (float) config('scalability.queues.slow_delay_seconds', 15)) {
                $redis->hincrby($hash, 'queue_slow', 1);
            }
            $redis->hincrby($this->key('queue-jobs:'.$bucket), $this->safeField($job), 1);
            $redis->expire($hash, $ttl);
            $redis->expire($this->key('queue-jobs:'.$bucket), $ttl);
        });
    }

    public function recordQueueFailure(string $job, ?string $queue = null): void
    {
        if (! $this->enabled()) return;
        $this->safely(function (Connection $redis) use ($job, $queue): void {
            $bucket = $this->bucket();
            $ttl = $this->ttlSeconds();
            $hash = $this->key('minute:'.$bucket);
            $redis->hincrby($hash, 'queue_failures', 1);
            $redis->hincrby($this->key('queue-failures:'.$bucket), $this->safeField(($queue ?: 'default').':'.$job), 1);
            $redis->expire($hash, $ttl);
            $redis->expire($this->key('queue-failures:'.$bucket), $ttl);
        });
    }

    public function recordRealtimeClient(string $event): void
    {
        if (! $this->enabled()) return;
        $event = in_array($event, ['connected', 'reconnect', 'error', 'unavailable', 'disconnected'], true) ? $event : 'other';
        $this->safely(function (Connection $redis) use ($event): void {
            $bucket = $this->bucket();
            $key = $this->key('realtime:'.$bucket);
            $redis->hincrby($key, $event, 1);
            $redis->expire($key, $this->ttlSeconds());
        });
    }

    public function snapshot(?int $minutes = null): array
    {
        $minutes = max(1, min(180, $minutes ?? (int) config('observability.window_minutes', 15)));
        $empty = $this->emptySnapshot($minutes);
        if (! $this->enabled()) return $empty + ['enabled' => false];

        try {
            $redis = $this->connection();
            $durations = $queryTimes = $memory = $queueDelays = [];
            $totals = [
                'requests' => 0, 'errors' => 0, 'slow_queries' => 0,
                'cache_hits' => 0, 'cache_misses' => 0,
                'queue_starts' => 0, 'queue_slow' => 0, 'queue_failures' => 0,
            ];
            $realtime = ['connected' => 0, 'reconnect' => 0, 'error' => 0, 'unavailable' => 0, 'disconnected' => 0, 'other' => 0];

            foreach ($this->buckets($minutes) as $bucket) {
                $hash = (array) $redis->hgetall($this->key('minute:'.$bucket));
                foreach ($totals as $field => $_) $totals[$field] += (int) ($hash[$field] ?? 0);
                $durations = array_merge($durations, $this->floatList($redis->lrange($this->key('duration:'.$bucket), 0, -1)));
                $queryTimes = array_merge($queryTimes, $this->floatList($redis->lrange($this->key('query:'.$bucket), 0, -1)));
                $memory = array_merge($memory, $this->floatList($redis->lrange($this->key('memory:'.$bucket), 0, -1)));
                $queueDelays = array_merge($queueDelays, $this->floatList($redis->lrange($this->key('queue-delay:'.$bucket), 0, -1)));
                $rt = (array) $redis->hgetall($this->key('realtime:'.$bucket));
                foreach ($realtime as $field => $_) $realtime[$field] += (int) ($rt[$field] ?? 0);
            }

            $cacheTotal = $totals['cache_hits'] + $totals['cache_misses'];
            $rtAttempts = $realtime['connected'] + $realtime['reconnect'] + $realtime['error'] + $realtime['unavailable'];

            return [
                'enabled' => true,
                'window_minutes' => $minutes,
                'requests' => $totals['requests'],
                'http_error_rate_percent' => $this->rate($totals['errors'], $totals['requests']),
                'request_ms' => $this->percentiles($durations),
                'query_time_ms' => $this->percentiles($queryTimes),
                'memory_peak_mb' => $this->percentiles($memory),
                'slow_queries' => $totals['slow_queries'],
                'cache_hit_rate_percent' => $cacheTotal > 0 ? round(100 * $totals['cache_hits'] / $cacheTotal, 2) : null,
                'queue' => [
                    'starts' => $totals['queue_starts'],
                    'slow' => $totals['queue_slow'],
                    'failures' => $totals['queue_failures'],
                    'delay_seconds' => $this->percentiles($queueDelays),
                ],
                'realtime' => $realtime + [
                    'error_rate_percent' => $this->rate($realtime['error'] + $realtime['unavailable'], $rtAttempts),
                ],
            ];
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
            return $empty + ['enabled' => true, 'available' => false, 'error' => $exception->getMessage()];
        }
    }

    public function alerts(array $snapshot): array
    {
        if (! ($snapshot['enabled'] ?? false) || ($snapshot['available'] ?? true) === false) return [];
        $limits = (array) config('observability.alerts', []);
        $alerts = [];
        $this->above($alerts, 'http_error_rate_percent', $snapshot['http_error_rate_percent'] ?? 0, $limits['http_error_rate_percent'] ?? 1);
        $this->above($alerts, 'request_p95_ms', data_get($snapshot, 'request_ms.p95', 0), $limits['request_p95_ms'] ?? 500);
        $this->above($alerts, 'query_p95_ms', data_get($snapshot, 'query_time_ms.p95', 0), $limits['query_p95_ms'] ?? 400);
        $this->above($alerts, 'memory_p95_mb', data_get($snapshot, 'memory_peak_mb.p95', 0), $limits['memory_p95_mb'] ?? 192);
        $this->above($alerts, 'queue_delay_p95_seconds', data_get($snapshot, 'queue.delay_seconds.p95', 0), $limits['queue_delay_p95_seconds'] ?? 15);
        $this->above($alerts, 'realtime_error_rate_percent', data_get($snapshot, 'realtime.error_rate_percent', 0), $limits['realtime_error_rate_percent'] ?? 5);
        $cacheHit = $snapshot['cache_hit_rate_percent'] ?? null;
        $cacheMin = (float) ($limits['cache_hit_rate_percent_min'] ?? 60);
        if ($cacheHit !== null && $cacheHit < $cacheMin) $alerts[] = ['metric' => 'cache_hit_rate_percent', 'value' => $cacheHit, 'limit' => $cacheMin, 'direction' => 'below'];
        if ((int) data_get($snapshot, 'queue.failures', 0) > 0) $alerts[] = ['metric' => 'queue_failures', 'value' => (int) data_get($snapshot, 'queue.failures', 0), 'limit' => 0, 'direction' => 'above'];
        return $alerts;
    }

    private function enabled(): bool
    {
        return (bool) config('observability.enabled', false);
    }

    private function connection(): Connection
    {
        return Redis::connection((string) config('observability.redis_connection', 'observability'));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback($this->connection());
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }
    }

    private function reportFailure(Throwable $exception): void
    {
        if ($this->reportedFailure) return;
        $this->reportedFailure = true;
        Log::notice('flowtrack.observability.unavailable', ['error' => $exception->getMessage()]);
    }

    private function sample(Connection $redis, string $suffix, float $value, int $ttl): void
    {
        $key = $this->key($suffix);
        $redis->lpush($key, (string) round($value, 3));
        $redis->ltrim($key, 0, max(0, (int) config('observability.samples_per_minute', 240) - 1));
        $redis->expire($key, $ttl);
    }

    private function bucket(?int $timestamp = null): string
    {
        return gmdate('YmdHi', $timestamp ?? time());
    }

    private function buckets(int $minutes): array
    {
        $now = time();
        $buckets = [];
        for ($i = 0; $i < $minutes; $i++) $buckets[] = $this->bucket($now - ($i * 60));
        return $buckets;
    }

    private function key(string $suffix): string
    {
        return (string) config('observability.prefix', 'flowtrack:observability:').$suffix;
    }

    private function ttlSeconds(): int
    {
        return max(1800, (int) config('observability.retention_minutes', 180) * 60);
    }

    private function floatList(array $values): array
    {
        return array_map(static fn ($value): float => (float) $value, $values);
    }

    private function percentiles(array $values): array
    {
        if ($values === []) return ['samples' => 0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0];
        sort($values, SORT_NUMERIC);
        return [
            'samples' => count($values),
            'p50' => $this->percentile($values, 0.50),
            'p95' => $this->percentile($values, 0.95),
            'p99' => $this->percentile($values, 0.99),
            'max' => round((float) end($values), 2),
        ];
    }

    private function percentile(array $values, float $quantile): float
    {
        $index = (int) ceil($quantile * count($values)) - 1;
        return round((float) $values[max(0, min(count($values) - 1, $index))], 2);
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round(100 * $part / $total, 2) : 0.0;
    }

    private function safeField(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $value) ?: 'unknown', 0, 160);
    }

    private function above(array &$alerts, string $metric, mixed $value, mixed $limit): void
    {
        $value = (float) $value;
        $limit = (float) $limit;
        if ($value > $limit) $alerts[] = ['metric' => $metric, 'value' => $value, 'limit' => $limit, 'direction' => 'above'];
    }

    private function emptySnapshot(int $minutes): array
    {
        return [
            'enabled' => false,
            'available' => true,
            'window_minutes' => $minutes,
            'requests' => 0,
            'http_error_rate_percent' => 0.0,
            'request_ms' => $this->percentiles([]),
            'query_time_ms' => $this->percentiles([]),
            'memory_peak_mb' => $this->percentiles([]),
            'slow_queries' => 0,
            'cache_hit_rate_percent' => null,
            'queue' => ['starts' => 0, 'slow' => 0, 'failures' => 0, 'delay_seconds' => $this->percentiles([])],
            'realtime' => ['connected' => 0, 'reconnect' => 0, 'error' => 0, 'unavailable' => 0, 'disconnected' => 0, 'other' => 0, 'error_rate_percent' => 0.0],
        ];
    }
}
