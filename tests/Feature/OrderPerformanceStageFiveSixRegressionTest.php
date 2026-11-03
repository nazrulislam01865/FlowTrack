<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderPerformanceStageFiveSixRegressionTest extends TestCase
{
    public function test_order_header_team_does_not_lazy_load_task_assignees(): void
    {
        $viewService = file_get_contents(app_path('Services/OrderDetailViewService.php'));
        $presenter = file_get_contents(app_path('Support/BoardPresenter.php'));

        $this->assertStringContainsString(
            <<<'PHP'
'team' => $this->summaryTeam($job)
PHP,
            $viewService,
        );
        $this->assertStringContainsString('private function summaryTeam(FlowJob $job): Collection', $viewService);
        $this->assertStringContainsString("\$job->relationLoaded('members')", $viewService);
        $this->assertStringNotContainsString('private function summaryTeam(FlowJob $job): Collection', $presenter);
    }

    public function test_initial_workflow_load_reuses_auto_advance_order_and_cached_published_phases(): void
    {
        $component = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $binding = file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));
        $legacy = file_get_contents(app_path('Services/LegacyJobService.php'));
        $action = file_get_contents(app_path('Actions/Orders/AutoAdvanceOrder.php'));

        $this->assertStringContainsString('private ?FlowJob $preparedWorkflowJob = null;', $component);
        // The same invocation is now measured, while preserving the prepared-job handoff.
        $this->assertStringContainsString("\$this->preparedWorkflowJob = OrderBaselineRecorder::measure('workflow_auto_advance'", $component);
        $this->assertStringContainsString('fn () => app(AutoAdvanceOrder::class)->handle($runtimeJob, auth()->user())', $component);
        $this->assertStringContainsString('$job = $this->preparedWorkflowJob;', $component);
        $this->assertStringContainsString('public function cachedPublishedPhasesForRead(int $workflowId): ?Collection', $binding);
        $this->assertStringContainsString('$cachedPublishedPhases = $binding->cachedPublishedPhasesForRead($workflowId);', $legacy);
        $this->assertStringContainsString("\$currentPhase->load(['taskPack.items.documentCategory']);", $legacy);
        $this->assertStringContainsString('public function maybeAutoAdvance(FlowJob $job, User $actor): FlowJob', $legacy);
        $this->assertStringContainsString('public function handle(FlowJob $order, User $actor): FlowJob', $action);
    }

    public function test_workflow_shipments_use_loaded_collection_instead_of_preflight_exists_query(): void
    {
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $start = strpos($service, 'public function loadVisibleOverviewWorkflow');
        $end = strpos($service, 'public function loadVisibleOverviewDocuments', $start);
        $method = substr($service, $start, $end - $start);

        $this->assertStringNotContainsString('$job->shipments()->exists()', $method);
        $this->assertStringContainsString('$job->shipments->isEmpty()', $method);
    }
}
