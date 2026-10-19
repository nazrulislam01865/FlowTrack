<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowBatchSyncPerformanceTest extends TestCase
{
    public function test_task_pack_sync_rescans_parent_once_per_phase_not_once_per_task(): void
    {
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $flags = file_get_contents(app_path('Services/OrderTaskFlagService.php'));

        $this->assertStringContainsString(
            '$task = $orderTaskRules->syncTask($task->refresh(), false);',
            $service,
        );
        $this->assertStringContainsString('$orderTaskRules->syncJob($freshJob);', $service);
        $this->assertStringContainsString('public function syncTask(Task $task, bool $syncParent = true): Task', $flags);
        $this->assertStringContainsString('if ($syncParent) {', $flags);
    }

    public function test_order_flag_resolution_uses_cached_master_data_instead_of_per_task_queries(): void
    {
        $flags = file_get_contents(app_path('Services/OrderTaskFlagService.php'));
        $start = strpos($flags, 'public function orderFlagForTaskFlag');
        $end = strpos($flags, 'public function effectiveTaskFlag', $start);
        $method = substr($flags, $start, $end - $start);

        $this->assertStringContainsString('$this->activeOrderFlags()->first(', $method);
        $this->assertStringNotContainsString('MasterRecord::query()', $method);
    }
    public function test_order_open_does_not_repeat_artwork_repair_after_successful_workflow_sync(): void
    {
        $detail = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderDetail.php'));

        $this->assertStringContainsString(
            '$workflowWasSynced = app(\\App\\Services\\OrderWorkflowBindingService::class)->syncSingleActiveOrder($id);',
            $detail,
        );
        $this->assertStringContainsString('if (!$workflowWasSynced) {', $detail);
    }

    public function test_phase_selection_uses_summary_loader_not_full_overview_loader(): void
    {
        $navigation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderNavigation.php'));
        $start = strpos($navigation, 'public function selectOverviewPhase');
        $end = strpos($navigation, 'public function showCurrentOverviewPhase', $start);
        $method = substr($navigation, $start, $end - $start);

        $this->assertStringContainsString('loadOverviewSummary($job, $user)', $method);
        $this->assertStringNotContainsString("loadTab($job, $user, 'overview')", $method);
    }

}
