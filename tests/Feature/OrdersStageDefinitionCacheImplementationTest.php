<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrdersStageDefinitionCacheImplementationTest extends TestCase
{
    public function test_orders_stage_cache_contains_only_stable_definition_metadata(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString('STAGE_DEFINITION_CACHE_TTL_MINUTES = 10', $service);
        $this->assertStringContainsString('stageDefinitionCacheKey', $service);
        $this->assertStringContainsString('cachedStageDefinitions()', $service);
        $this->assertStringContainsString("->get(['id', 'name', 'short_name', 'sequence', 'color'])", $service);

        // Live operational counts must remain outside the cached resolver.
        $cacheMethodStart = strpos($service, 'private function cachedStageDefinitions');
        $cacheMethodEnd = strpos($service, 'private function validStageDefinitionRows', $cacheMethodStart);
        $cacheMethod = substr($service, $cacheMethodStart, $cacheMethodEnd - $cacheMethodStart);
        $this->assertStringNotContainsString('JobService::class', $cacheMethod);
        $this->assertStringNotContainsString('countQuery', $cacheMethod);
        $this->assertStringNotContainsString('flow_jobs', $cacheMethod);
    }

    public function test_workflow_edits_invalidate_orders_stage_definition_cache(): void
    {
        $workflowService = file_get_contents(app_path('Services/WorkflowService.php'));
        $orderWorkflowService = file_get_contents(app_path('Services/OrderWorkflowSetupService.php'));

        $this->assertStringContainsString('OrderListPrototypeService::stageDefinitionCacheKey($workspaceId)', $workflowService);
        $this->assertStringContainsString('Cache::forget($ordersStageKey)', $workflowService);
        $this->assertStringContainsString('DB::afterCommit', $workflowService);
        $this->assertStringContainsString('Cache::forget(OrderListPrototypeService::stageDefinitionCacheKey($workspaceId));', $orderWorkflowService);
    }
}
