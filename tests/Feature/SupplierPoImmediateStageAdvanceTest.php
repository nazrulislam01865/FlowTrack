<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Supplier PO generation must have the same canonical progression as an ERP task. */
final class SupplierPoImmediateStageAdvanceTest extends TestCase
{
    public function test_supplier_generation_completes_task_and_runs_canonical_progression_in_one_transaction(): void
    {
        $po = file_get_contents(app_path('Services/Orders/SupplierPurchaseOrderService.php'));
        $tasks = file_get_contents(app_path('Services/TaskService.php'));

        $this->assertStringContainsString('$this->completeOptionalArtworkStep($lockedJob, $actor, $onlySupplierId);', $po);
        $this->assertStringContainsString('->where(\'supplier_id\', $supplierId)', $po);
        $this->assertStringContainsString('->where(\'is_active\', true)', $po);
        $this->assertStringContainsString('canEditTask($delegate, $task)', $po);
        $this->assertStringContainsString('->reconcileAfterVerifiedSupplierCompletion($task, $delegate)', $po);
        $this->assertStringContainsString('public function reconcileAfterVerifiedSupplierCompletion(Task $task, User $delegate): void', $tasks);
        $this->assertStringContainsString('$this->refreshJobState($task, $delegate);', $tasks);
        $this->assertStringContainsString('app(OrderTaskSequenceService::class)->synchronizeCurrentPhase($job, $actor);', $tasks);
        $this->assertStringContainsString('app(JobService::class)->recalculateProgress($job->refresh());', $tasks);
        $this->assertStringContainsString('app(JobService::class)->maybeAutoAdvance($job->refresh(), $actor);', $tasks);
        $this->assertStringContainsString('DB::transaction(function () use ($actor, $job, $groups', $po);
    }

    public function test_coalesced_workspace_events_refresh_both_order_workflow_and_parent_header(): void
    {
        $workflow = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $parent = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $this->assertStringContainsString("#[On('flowtrack-order-changed')]", $workflow);
        $this->assertStringContainsString("#[On('flowtrack-refresh')]", $workflow);
        $this->assertStringContainsString("#[On('flowtrack-refresh')]", $parent);
        $regex = '/(?:^|\|)(?:Task|FlowJob|Document|FlowJobItem|FlowJobPhaseHistory):/';
        $this->assertStringContainsString($regex, $workflow);
        $this->assertStringContainsString($regex, $parent);
        $this->assertSame(1, preg_match($regex, 'Activity:created|Document:created|Task:updated|FlowJob:updated'));
        $this->assertSame(0, preg_match($regex, 'MasterRecord:updated|NotificationRule:updated'));
    }
}
