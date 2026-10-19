# FlowTrack observability

Phase 15 promotes the existing request/query/queue instrumentation into one bounded operational metrics owner: `App\Services\Observability\OperationsMetrics`.

## Signals

The rolling Redis window records request latency and errors, query time and slow-query count, peak request memory, cache hits/misses, queue delay/failures, and Reverb client connection/reconnect/error state. Samples are bounded per minute and expire automatically; this is operational telemetry, not an analytics warehouse.

Default review thresholds are configured in `config/observability.php`: standard request p95 500 ms, query-time p95 400 ms, HTTP error rate 1%, request memory p95 192 MB, cache hit rate 60%, queue delay p95 15 seconds, and realtime error rate 5%. Production may tune these through environment variables without changing application code.

## Commands

```bash
php artisan flowtrack:observability:snapshot
php artisan flowtrack:observability:snapshot --minutes=30 --json
php artisan flowtrack:observability:check
```

When observability is enabled, the scheduler runs the alert check every five minutes with `onOneServer()` and `withoutOverlapping()`. Alert records use `flowtrack.observability.alert`. Existing queue telemetry continues to emit `flowtrack.queue.delay`, `flowtrack.queue.failed`, and `flowtrack.queue.busy`.

## Realtime telemetry

Authenticated clients post only coarse connection state to the internal `telemetry.realtime` endpoint. The browser module de-duplicates identical events for 15 seconds. No message contents are included. Disable the endpoint with `FLOWTRACK_REALTIME_TELEMETRY=false`.

## Storage and failure behavior

The metrics store uses the dedicated Redis `observability` connection (database 5 by default). Metrics failure never fails a business request; the service logs `flowtrack.observability.unavailable` once and degrades safely.
