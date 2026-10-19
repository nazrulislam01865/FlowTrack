<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardInquiryWorkflowPhaseImplementationTest extends TestCase
{
    public function test_inquiry_tasks_persist_their_source_workflow_phase_for_dashboard_flow(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_17_221500_add_workflow_phase_to_inquiry_tasks.php'));
        $service = $this->inquiryServiceSource();
        $model = file_get_contents(app_path('Models/InquiryTask.php'));
        $component = $this->inquiryLivewireSource();

        $this->assertStringContainsString("source_workflow_phase_id", $migration);
        $this->assertStringContainsString("'phase_id' => (int) \$phase->id", $service);
        $this->assertStringContainsString("'source_workflow_phase_id' => (\$task['phase_id'] ?? null) ?: null", $service);
        $this->assertStringContainsString('sourceWorkflowPhase()', $model);
        $this->assertStringContainsString("'phase_id' => \$task->source_workflow_phase_id", $component);
    }

    public function test_dashboard_inquiry_flow_uses_the_same_phase_driven_principle_as_orders(): void
    {
        $dashboard = file_get_contents(app_path('Services/LegacyDashboardService.php'));

        $this->assertStringContainsString('dashboard_source_workflow_phase_id', $dashboard);
        $this->assertStringContainsString('source_workflow_phase_id', $dashboard);
        $this->assertStringContainsString('phasesByWorkflowPack', $dashboard);
        $this->assertStringContainsString('phaseRangesByWorkflow', $dashboard);
        $this->assertStringContainsString('compileWorkflowPhaseDistribution($definitions, $counts, $workflowIds, $activeClientsByWorkflow)', $dashboard);
        $this->assertStringContainsString("private const CACHE_VERSION = 'v19-shipping-phase-compat'", $dashboard);
    }
}
