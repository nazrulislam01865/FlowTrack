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

    public function test_other_livewire_components_are_not_profiled(): void
    {
        config()->set('order_baseline.enabled', true);
        $request = Request::create('/livewire/update', 'POST', [
            'components' => [['snapshot' => json_encode(['memo' => ['name' => 'dashboard.index']])]],
        ]);
        $this->assertNull(OrderBaselineRecorder::httpContext($request));
    }
}
