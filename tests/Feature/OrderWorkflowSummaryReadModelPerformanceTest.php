<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowSummaryReadModelPerformanceTest extends TestCase
{
    public function test_initial_order_shell_uses_materialized_workflow_summary_instead_of_task_graph(): void
    {
        $builder = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $summary = file_get_contents(resource_path('views/components/jobs/order-detail/summary.blade.php'));

        $jobPageStart = strpos($builder, 'private function jobPageData(User $user): array');
        $jobPageEnd = strpos($builder, 'private function ', $jobPageStart + 20);
        $jobPage = substr($builder, $jobPageStart, $jobPageEnd - $jobPageStart);

        $this->assertStringContainsString('OrderWorkflowSummaryService::class)->forViewer($selected, $user)', $jobPage);
        $this->assertStringNotContainsString('loadOverviewShell($selected, $user)', $jobPage);
        $this->assertStringContainsString(':summary="$workflowSummary"', $overview);
        $this->assertStringNotContainsString('OrderDetailPresenter::currentTasks', $overview);
        $this->assertStringNotContainsString('OrderDetailPresenter::nextTask', $overview);
        $this->assertStringContainsString("summary['next_task_id']", $summary);
        $this->assertStringContainsString("summary['next_task_title']", $summary);
    }

    public function test_read_model_is_derived_and_selectively_invalidated(): void
    {
        $service = file_get_contents(app_path('Services/Orders/OrderWorkflowSummaryService.php'));
        $jobService = file_get_contents(app_path('Services/LegacyJobService.php'));
        $taskService = file_get_contents(app_path('Services/TaskService.php'));
        $documentService = file_get_contents(app_path('Services/DocumentService.php'));
        $taskPackService = file_get_contents(app_path('Services/TaskPackService.php'));
        $holdService = file_get_contents(app_path('Services/Orders/OrderHoldService.php'));
        $bindingService = file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));
        $orderTable = file_get_contents(resource_path('views/components/jobs/table.blade.php'));

        $this->assertStringContainsString('OrderDetailPresenter::nextTask($working)', $service);
        $this->assertStringContainsString('applyTaskScope($taskQuery, $viewer)', $service);
        $this->assertStringContainsString('markStale((int) $job->id)', $jobService);
        $this->assertStringContainsString('updateNextTaskAssignee($task)', $taskService);
        $this->assertStringContainsString('invalidateOrderWorkflowSummary', $documentService);
        $this->assertStringContainsString('markStaleForTaskPackItem((int) $item->id)', $taskPackService);
        $this->assertStringContainsString('publishMappedOrderWorkflows((int) $pack->id, $id === null)', $taskPackService);
        $this->assertStringContainsString('if ($invalidateWorkflowSummaries)', $taskPackService);
        $this->assertStringContainsString('updateHoldState((int) $job->id, true)', $holdService);
        $this->assertStringContainsString('updateHoldState((int) $job->id, false)', $holdService);
        $this->assertStringContainsString('markStale($jobId)', $bindingService);
        $this->assertStringContainsString('wire:navigate.hover', $orderTable);
    }

    public function test_read_model_schema_and_backfill_command_are_present(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_10_190000_create_order_workflow_summaries_table.php'));
        $command = file_get_contents(app_path('Console/Commands/RebuildOrderWorkflowSummaries.php'));

        $this->assertStringContainsString("Schema::create('order_workflow_summaries'", $migration);
        $this->assertStringContainsString("\$table->foreignId('flow_job_id')->unique()", $migration);
        $this->assertStringContainsString('flowtrack:rebuild-order-workflow-summaries', $command);
        $this->assertStringContainsString('chunkById($chunk', $command);
    }
}
