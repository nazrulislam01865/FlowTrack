<?php

namespace Tests\Unit;

use App\Services\Observability\OperationsMetrics;
use Tests\TestCase;

class OperationsMetricsAlertsTest extends TestCase
{
    public function test_alert_evaluation_is_deterministic_without_redis_io(): void
    {
        config()->set('observability.alerts', [
            'http_error_rate_percent' => 1,
            'request_p95_ms' => 500,
            'query_p95_ms' => 400,
            'memory_p95_mb' => 192,
            'cache_hit_rate_percent_min' => 60,
            'queue_delay_p95_seconds' => 15,
            'realtime_error_rate_percent' => 5,
        ]);

        $snapshot = [
            'enabled' => true,
            'http_error_rate_percent' => 2.0,
            'request_ms' => ['p95' => 700],
            'query_time_ms' => ['p95' => 450],
            'memory_peak_mb' => ['p95' => 200],
            'cache_hit_rate_percent' => 50,
            'queue' => ['failures' => 1, 'delay_seconds' => ['p95' => 20]],
            'realtime' => ['error_rate_percent' => 6],
        ];

        $metrics = new OperationsMetrics();
        $names = array_column($metrics->alerts($snapshot), 'metric');

        foreach (['http_error_rate_percent', 'request_p95_ms', 'query_p95_ms', 'memory_p95_mb', 'cache_hit_rate_percent', 'queue_delay_p95_seconds', 'queue_failures', 'realtime_error_rate_percent'] as $metric) {
            $this->assertContains($metric, $names);
        }
    }
}
