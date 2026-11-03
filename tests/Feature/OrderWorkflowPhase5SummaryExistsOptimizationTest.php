<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FlowJob;
use App\Models\TaskPack;
use App\Models\WorkflowPhase;
use App\Models\Task;
use App\Models\TaskLink;
use App\Models\TaskPackItem;
use App\Support\OrderDetailPresenter;
use Tests\TestCase;

/**
 * Verify the opt-in summary's SQL EXISTS flags preserve the same optional
 * task visibility semantics as the original eager-loaded collections.
 * Deliberately no DB writes, fixtures or migrations are required.
 */
class OrderWorkflowPhase5SummaryExistsOptimizationTest extends TestCase
{
    private function sampleTask(string $status = 'Locked'): Task
    {
        $task = new Task([
            'title' => 'Sample Approval (when required)',
            'status' => $status,
            'progress' => 0,
            // It must represent a generated Task Pack item, not a manually added task.
            'task_pack_task_id' => 71,
        ]);
        $task->setRelation('setupTemplate', new TaskPackItem([
            'is_required' => false,
            'automation_key' => 'ART_SAMPLE_APPROVAL',
            'sort_order' => 5,
        ]));

        return $task;
    }

    public function test_untouched_conditional_task_without_evidence_remains_hidden(): void
    {
        $task = $this->sampleTask();
        $task->setAttribute('documents_exists', false);
        $task->setAttribute('links_exists', false);

        $this->assertFalse(OrderDetailPresenter::isConditionalTaskActivated($task));
        $this->assertFalse(OrderDetailPresenter::isApplicableTask($task));
        $this->assertFalse($task->relationLoaded('documents'));
        $this->assertFalse($task->relationLoaded('links'));
    }

    public function test_manual_task_remains_applicable_without_a_task_pack_item(): void
    {
        // Existing business rule: manually added tasks have no Task Pack item
        // and must remain visible even when their status is Locked.
        $task = new Task(['title' => 'Manual review', 'status' => 'Locked']);

        $this->assertNull($task->task_pack_task_id);
        $this->assertTrue(OrderDetailPresenter::isApplicableTask($task));
    }

    public function test_document_and_link_exists_activate_conditional_tasks_without_loading_relations(): void
    {
        $documentTask = $this->sampleTask();
        $documentTask->setAttribute('documents_exists', true);
        $documentTask->setAttribute('links_exists', false);
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($documentTask));
        $this->assertTrue(OrderDetailPresenter::isApplicableTask($documentTask));
        $this->assertFalse($documentTask->relationLoaded('documents'));

        $linkTask = $this->sampleTask();
        $linkTask->setAttribute('documents_exists', false);
        $linkTask->setAttribute('links_exists', true);
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($linkTask));
        $this->assertTrue(OrderDetailPresenter::isApplicableTask($linkTask));
        $this->assertFalse($linkTask->relationLoaded('links'));
    }

    public function test_eager_loaded_relations_remain_the_source_of_truth_when_present(): void
    {
        $task = $this->sampleTask();
        $task->setAttribute('documents_exists', false);
        $task->setAttribute('links_exists', false);
        $task->setRelation('documents', collect([new Document()]));
        $task->setRelation('links', collect());
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($task));

        $task->setAttribute('documents_exists', true);
        $task->setAttribute('links_exists', true);
        $task->setRelation('documents', collect());
        $task->setRelation('links', collect());
        $this->assertFalse(OrderDetailPresenter::isConditionalTaskActivated($task));

        $task->setRelation('links', collect([new TaskLink()]));
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($task));
    }

    public function test_skipped_tasks_and_existing_activation_rules_take_precedence(): void
    {
        $skipped = $this->sampleTask('Skipped');
        $skipped->setAttribute('documents_exists', true);
        $this->assertFalse(OrderDetailPresenter::isConditionalTaskActivated($skipped));
        $this->assertFalse(OrderDetailPresenter::isApplicableTask($skipped));

        $progress = $this->sampleTask();
        $progress->progress = 50;
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($progress));

        $active = $this->sampleTask('In Progress');
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($active));

        $completed = $this->sampleTask('Completed');
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($completed));
    }

    public function test_current_phase_next_task_matches_legacy_evidence_loading(): void
    {
        $item = new TaskPackItem([
            'id' => 71,
            'task_pack_id' => 41,
            'is_required' => false,
            'automation_key' => 'ART_SAMPLE_APPROVAL',
            'sort_order' => 5,
        ]);
        $pack = new TaskPack();
        $pack->setRelation('items', collect([$item]));
        $phase = new WorkflowPhase();
        $phase->forceFill(['id' => 31, 'task_pack_id' => 41]);
        $phase->setRelation('taskPack', $pack);

        foreach ([false, true] as $hasEvidence) {
            $legacyTask = $this->sampleTask();
            $legacyTask->forceFill(['id' => 91, 'task_pack_task_id' => 71, 'workflow_phase_id' => 31]);
            $legacyTask->setRelation('documents', $hasEvidence ? collect([new Document()]) : collect());
            $legacyTask->setRelation('links', collect());

            $existsTask = $this->sampleTask();
            $existsTask->forceFill(['id' => 91, 'task_pack_task_id' => 71, 'workflow_phase_id' => 31]);
            $existsTask->setAttribute('documents_exists', $hasEvidence);
            $existsTask->setAttribute('links_exists', false);

            $legacyOrder = new FlowJob(['status' => 'In Progress']);
            $legacyOrder->forceFill(['workflow_phase_id' => 31]);
            $legacyOrder->setRelation('phase', $phase);
            $legacyOrder->setRelation('tasks', collect([$legacyTask]));

            $existsOrder = new FlowJob(['status' => 'In Progress']);
            $existsOrder->forceFill(['workflow_phase_id' => 31]);
            $existsOrder->setRelation('phase', $phase);
            $existsOrder->setRelation('tasks', collect([$existsTask]));

            $this->assertSame(
                OrderDetailPresenter::nextTask($legacyOrder)?->id,
                OrderDetailPresenter::nextTask($existsOrder)?->id,
            );
            $this->assertSame(
                OrderDetailPresenter::currentTasks($legacyOrder)->count(),
                OrderDetailPresenter::currentTasks($existsOrder)->count(),
            );
        }
    }

    public function test_exists_projection_is_part_of_one_bounded_task_select(): void
    {
        // Query compilation only. No Task/Document/Link rows are read or written.
        $sql = strtolower(Task::query()
            ->where('flow_job_id', 332)
            ->where('workflow_phase_id', 333)
            ->select(['id', 'flow_job_id', 'workflow_phase_id'])
            ->withExists([
                'documents',
                'links' => fn ($query) => $query->reorder(),
            ])
            ->toSql());

        $this->assertStringContainsString('documents_exists', $sql);
        $this->assertStringContainsString('links_exists', $sql);
        preg_match_all('/\bexists\s*\(/', $sql, $matches);
        $this->assertCount(2, $matches[0]);
        $this->assertStringContainsString('flow_job_id', $sql);
        $this->assertStringContainsString('workflow_phase_id', $sql);
    }

    public function test_summary_has_legacy_fallback_and_visibility_is_applied_before_fetch(): void
    {
        $service = file_get_contents(app_path('Services/Orders/OrderWorkflowSummaryService.php'));
        $config = file_get_contents(config_path('flowtrack.php'));
        $presenter = file_get_contents(app_path('Support/OrderDetailPresenter.php'));

        $this->assertStringContainsString("config('flowtrack.workflow_summary_evidence_exists_enabled', false)", $service);
        $this->assertStringContainsString("'documents:id,task_id'", $service);
        $this->assertStringContainsString("'links:id,task_id,url,created_at'", $service);
        $this->assertStringContainsString("$".'taskQuery->withExists(', $service);
        $this->assertStringContainsString('applyTaskScope($taskQuery, $viewer)', $service);
        $this->assertStringContainsString("'workflow_summary_evidence_exists_enabled'", $config);
        $this->assertStringContainsString("array_key_exists('documents_exists'", $presenter);
        $this->assertStringContainsString("array_key_exists('links_exists'", $presenter);
        $this->assertStringContainsString("'assignee:id,name,profile_image_path'", $service);
    }
}
