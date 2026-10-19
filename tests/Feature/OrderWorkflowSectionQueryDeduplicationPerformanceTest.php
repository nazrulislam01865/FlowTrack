<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowSectionQueryDeduplicationPerformanceTest extends TestCase
{
    public function test_order_workflow_definition_services_are_request_scoped(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertStringContainsString('$this->app->scoped(OrderWorkflowSetupService::class);', $provider);
        $this->assertStringContainsString('$this->app->scoped(OrderWorkflowBindingService::class);', $provider);
    }

    public function test_binding_reuses_one_published_phase_graph_without_skipping_readiness_rules(): void
    {
        $binding = file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));
        $setup = file_get_contents(app_path('Services/OrderWorkflowSetupService.php'));

        $this->assertStringContainsString('private array $publishedWorkflowCache = [];', $binding);
        $this->assertStringContainsString('private array $publishedPhaseCache = [];', $binding);
        $this->assertStringContainsString('$phases = $this->publishedPhasesForRead($workflowId);', $binding);
        $this->assertStringContainsString('! $setup->publishedPhasesAreReady($phases)', $binding);
        $this->assertStringContainsString('if ($this->runtimeMatchesPublishedDefinition($job, $workflow, $phases))', $binding);

        $syncStart = strpos($binding, 'public function syncSingleActiveOrder');
        $syncEnd = strpos($binding, 'private function runtimeMatchesPublishedDefinition', $syncStart);
        $this->assertNotFalse($syncStart);
        $this->assertNotFalse($syncEnd);

        $syncMethod = substr($binding, $syncStart, $syncEnd - $syncStart);
        $phaseRead = strpos($syncMethod, '$phases = $this->publishedPhasesForRead($workflowId);');
        $mirrorRead = strpos($syncMethod, '[$workflow, $phases] = $this->publishedWorkflow($workflowId);');
        $this->assertNotFalse($phaseRead);
        $this->assertNotFalse($mirrorRead);
        $this->assertLessThan($mirrorRead, $phaseRead);

        $this->assertStringContainsString('return $this->publishedPhasesAreReady($workflow->phases);', $setup);
        $this->assertStringContainsString('private function taskPackSupportsStage', $setup);
    }

    public function test_active_workflow_render_skips_legacy_phase_graph_and_reuses_published_graph(): void
    {
        $jobs = file_get_contents(app_path('Services/LegacyJobService.php'));

        $start = strpos($jobs, 'public function loadVisibleOverviewWorkflow');
        $end = strpos($jobs, 'public function loadVisibleOverviewDocuments', $start);
        $method = substr($jobs, $start, $end - $start);

        $this->assertStringContainsString("? 'workflow:id,name'", $method);
        $this->assertStringContainsString(": 'workflow.phases.taskPack.items.documentCategory'", $method);
        $this->assertStringContainsString('publishedPhasesForRead($workflowId)', $jobs);
        $this->assertStringContainsString('isActiveOrderWorkflow((int) $job->workflow_id)', $jobs);
    }
}
