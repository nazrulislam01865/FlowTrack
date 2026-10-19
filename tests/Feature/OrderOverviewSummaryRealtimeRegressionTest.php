<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderOverviewSummaryRealtimeRegressionTest extends TestCase
{
    public function test_lightweight_overview_shell_hydrates_current_task_pack_for_next_action(): void
    {
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $start = strpos($service, 'public function loadVisibleOverviewShell');
        $end = strpos($service, 'public function loadVisibleOverviewSummary', $start);
        $method = substr($service, $start, $end - $start);

        $this->assertStringContainsString("'workflow_template_id', 'task_pack_id'", $method);
        $this->assertStringContainsString("\$currentPhase->load(['taskPack.items:id,task_pack_id']);", $method);
        $this->assertStringContainsString("\$job->setRelation('phase', \$currentPhase);", $method);
    }

    public function test_isolated_workflow_actions_refresh_parent_summary_without_browser_reload(): void
    {
        $tasks = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTasks.php'));
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));

        $this->assertStringContainsString("\$this->dispatchOrderRuntimeRefresh();", $tasks);
        $this->assertStringContainsString("\$this->dispatch('order-runtime-refreshed', orderId: (int) \$this->selectedJobId);", $tasks);
        $this->assertStringContainsString("#[On('order-runtime-refreshed')]", $index);
        $this->assertStringContainsString('public function refreshOrderRuntime(int $orderId): void', $index);
    }
}
