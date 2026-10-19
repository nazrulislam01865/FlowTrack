<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\TestCase;

final class SupplierInquiryTaskBridgeThrottleIsolationTest extends TestCase
{
    public function test_inquiry_task_bridge_uses_its_own_limit(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/supplier-bridge/inquiry-task-actions', 'POST'));
        $this->assertContains('throttle:supplier-inquiry-task-bridge', $route->gatherMiddleware());
        $this->assertNotContains('throttle:20,1', $route->gatherMiddleware());
        $download = app('router')->getRoutes()->match(Request::create('/api/supplier-bridge/rfq-task-documents/7/99', 'GET'));
        $this->assertContains('throttle:supplier-inquiry-task-document-bridge', $download->gatherMiddleware());
        $this->assertNotContains('throttle:60,1', $download->gatherMiddleware());
    }
}
