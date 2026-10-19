<?php

namespace App\Services\Infrastructure;

use App\Services\Observability\OperationsMetrics;
use Illuminate\Support\Facades\Log;

final class QueueTelemetry
{
    public function __construct(private readonly OperationsMetrics $operationsMetrics) {}
    public function recordStart(string $job, int $enqueuedAt, array $context = []): void
    {
        $delay = max(0, time() - $enqueuedAt);
        $threshold = max(1, (int) config('scalability.queues.slow_delay_seconds', 15));
        $this->operationsMetrics->recordQueueDelay($job, $delay);
        $payload = array_merge($context, [
            'job' => $job,
            'delay_seconds' => $delay,
            'threshold_seconds' => $threshold,
        ]);

        if ($delay >= $threshold) {
            Log::warning('flowtrack.queue.delay', $payload);
        }
    }
}
