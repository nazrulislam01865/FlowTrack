<?php

namespace Tests\Unit;

use App\Support\Performance\OrderBaselineRecorder;
use Illuminate\Http\Request;
use Tests\TestCase;

final class OrderBaselineRecorderTest extends TestCase
{
    public function test_collector_is_disabled_by_default(): void
    {
        config()->set('order_baseline.enabled', false);
        $this->assertNull(OrderBaselineRecorder::httpContext(Request::create('/orders?open=123', 'GET')));
    }

    public function test_it_only_profiles_open_order_details_not_orders_list(): void
    {
        config()->set('order_baseline.enabled', true);
        $this->assertNull(OrderBaselineRecorder::httpContext(Request::create('/orders', 'GET')));
        $this->assertSame(['kind' => 'initial_get', 'order_id' => 123],
            OrderBaselineRecorder::httpContext(Request::create('/orders?open=123', 'GET')));
    }

    public function test_it_logs_only_component_and_method_without_livewire_payload(): void
    {
        config()->set('order_baseline.enabled', true);
        $snapshot = json_encode(['memo' => ['name' => 'jobs.order-workflow-section'],
            'data' => ['orderId' => 123, 'privateNote' => 'SENSITIVE_VALUE']]);
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => $snapshot,
                'calls' => [['method' => 'loadWorkflowSection', 'params' => ['SENSITIVE_VALUE']]],
            ]],
        ]);
        $result = OrderBaselineRecorder::httpContext($request);
        $this->assertSame('livewire_update', $result['kind']);
        $this->assertSame(123, $result['order_id']);
        $this->assertSame('jobs.order-workflow-section', $result['components'][0]['component']);
        $this->assertSame(['loadWorkflowSection'], $result['components'][0]['methods']);
        $this->assertStringNotContainsString('SENSITIVE_VALUE', json_encode($result));
    }

    public function test_it_profiles_a_named_livewire_route_even_with_a_versioned_url(): void
    {
        config()->set('order_baseline.enabled', true);
        $request = Request::create('/livewire-abcdef123456/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode([
                    'memo' => ['name' => 'jobs.order-workflow-section'],
                    'data' => ['orderId' => 329],
                ]),
                'calls' => [['method' => 'loadWorkflowSection']],
            ]],
        ]);
        $route = new \Illuminate\Routing\Route('POST', 'livewire-abcdef123456/update', static fn () => null);
        $route->name('default-livewire.update');
        $request->setRouteResolver(static fn () => $route);

        $context = OrderBaselineRecorder::httpContext($request);
        $this->assertSame('livewire_update', $context['kind']);
        $this->assertSame(329, $context['order_id']);
        $this->assertSame(['loadWorkflowSection'], $context['components'][0]['methods']);
    }

    public function test_a_versioned_route_is_not_profiled_without_the_livewire_route_name(): void
    {
        config()->set('order_baseline.enabled', true);
        $request = Request::create('/something-else/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => 'jobs.index'], 'data' => ['selectedJobId' => 329]]),
            ]],
        ]);
        $this->assertNull(OrderBaselineRecorder::httpContext($request));
    }

    public function test_parent_create_mode_is_not_misreported_as_an_order_details_update(): void
    {
        config()->set('order_baseline.enabled', true);
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [[
                'snapshot' => json_encode(['memo' => ['name' => 'jobs.index'], 'data' => ['selectedJobId' => null]]),
                'calls' => [['method' => 'saveNewOrder']],
            ]],
        ]);
        $this->assertNull(OrderBaselineRecorder::httpContext($request));
    }

    public function test_process_cpu_time_is_optional_and_nonnegative(): void
    {
        $cpu = OrderBaselineRecorder::processCpuMs();
        $this->assertTrue($cpu === null || $cpu >= 0);
    }

    public function test_disabled_spans_preserve_callback_result_without_writing_events(): void
    {
        config()->set('order_baseline.enabled', false);
        $invocations = 0;
        $result = OrderBaselineRecorder::measure('remote_area_match',
            function () use (&$invocations): string {
                $invocations++;
                return 'expected-result';
            }, 123);
        $this->assertSame('expected-result', $result);
        $this->assertSame(1, $invocations);
    }

    public function test_enabled_recorder_preserves_kind_and_captures_scoped_service_spans(): void
    {
        config()->set('order_baseline.enabled', true);
        config()->set('order_baseline.run_id', 'phase1_test');
        $originalStorage = storage_path();
        $originalRequest = app('request');
        $temporaryStorage = sys_get_temp_dir().'/flowtrack-order-baseline-'.bin2hex(random_bytes(8));
        mkdir($temporaryStorage.'/logs', 0700, true);
        app()->useStoragePath($temporaryStorage);
        app()->instance('request', Request::create('/orders?open=123', 'GET'));
        try {
            OrderBaselineRecorder::record('http_request', [
                'kind' => 'initial_get',
                'request_kind' => 'initial_get',
                'order_id' => 123,
            ]);
            $this->assertSame('ok', OrderBaselineRecorder::measure('remote_area_match', fn () => 'ok', 123));
            $lines = file($temporaryStorage.'/logs/order-details-baseline.jsonl', FILE_IGNORE_NEW_LINES);
            $this->assertCount(2, $lines);
            $http = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            $span = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('http_request', $http['kind']);
            $this->assertSame('initial_get', $http['request_kind']);
            $this->assertSame('service_span', $span['kind']);
            $this->assertSame('remote_area_match', $span['span']);
            $this->assertSame(123, $span['order_id']);
            $this->assertTrue($span['success']);
            $this->assertGreaterThanOrEqual(0, $span['duration_ms']);
        } finally {
            app()->instance('request', $originalRequest);
            app()->useStoragePath($originalStorage);
            @unlink($temporaryStorage.'/logs/order-details-baseline.jsonl');
            @rmdir($temporaryStorage.'/logs');
            @rmdir($temporaryStorage);
        }
    }

    public function test_other_livewire_components_are_not_profiled(): void
    {
        config()->set('order_baseline.enabled', true);
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [['snapshot' => json_encode(['memo' => ['name' => 'dashboard.index']])]],
        ]);
        $this->assertNull(OrderBaselineRecorder::httpContext($request));
    }
}
