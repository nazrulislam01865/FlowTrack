<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderStageFilterCurrentRuntimePhaseTest extends TestCase
{
    public function test_orders_stage_filter_uses_current_runtime_phase_not_source_phase_provenance(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString("whereIn('flow_jobs.workflow_phase_id', $phaseIds)", $service);
        $this->assertStringNotContainsString("whereIn('flow_jobs.source_workflow_phase_id', $phaseIds)", $service);
        $this->assertStringContainsString('stage-phase-map:v3', $service);
    }
}
