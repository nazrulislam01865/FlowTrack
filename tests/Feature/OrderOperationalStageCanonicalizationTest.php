<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderOperationalStageCanonicalizationTest extends TestCase
{
    public function test_order_list_uses_canonical_stage_resolver_for_rows_counts_and_filters(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString('use App\\Support\\OrderStageResolver;', $service);
        $this->assertStringContainsString('OrderStageResolver::resolve(', $service);
        $this->assertStringContainsString("'phase_name' => (string) \$stage['name']", $service);
        $this->assertStringContainsString('orderPhaseIdsForSequence', $service);
        $this->assertStringContainsString('OrderStageResolver::matchesSequence(', $service);
    }

    public function test_my_tasks_and_all_tasks_share_the_same_canonical_stage_contract(): void
    {
        $myWork = file_get_contents(app_path('Services/MyWorkService.php'));
        $allTasks = file_get_contents(app_path('Services/BoardTaskPackService.php'));

        $this->assertStringContainsString('OrderWorkflowSetupService::fixedStages()', $myWork);
        $this->assertStringContainsString('OrderStageResolver::resolve(', $myWork);
        $this->assertStringContainsString("'stage' => (string) \$jobStage['name']", $myWork);
        $this->assertStringContainsString("'phase' => (string) \$taskStage['short_name']", $myWork);

        $this->assertStringContainsString('OrderStageResolver::resolve(', $allTasks);
        $this->assertStringContainsString("'stage' => (string) \$jobStage['name']", $allTasks);
        $this->assertStringContainsString("'phase' => (string) \$taskStage['short_name']", $allTasks);
        $this->assertStringContainsString('return app(MyWorkService::class)->orderPhaseOptions();', $allTasks);
    }
    public function test_workflow_rebinding_uses_the_same_canonical_mapping_contract(): void
    {
        $binding = file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));

        $this->assertStringContainsString('use App\\Support\\OrderStageResolver;', $binding);
        $this->assertStringContainsString('OrderStageResolver::resolve(', $binding);
        $this->assertStringNotContainsString("Str::contains(\$name, ['new order', 'order intake'", $binding);
    }

}
