<?php

namespace Tests\Feature;

use App\Livewire\Jobs\OrderWorkflowSection;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\WorkflowPhase;
use App\Support\JobDetailPresenter;
use ReflectionMethod;
use Tests\TestCase;

/** Stage transition regressions without migrations or changes to saved orders. */
class OrderWorkflowStageAdvanceRegressionTest extends TestCase
{
    /** @return array{FlowJob, Task} */
    private function artworkWithSample(string $sampleStatus, bool $sampleRequired = false): array
    {
        config()->set('flowtrack.workflow_display_query_enabled', false);

        $sampleItem = new TaskPackItem([
            'id' => 502, 'task_pack_id' => 401, 'title' => 'Sample Approval (when required)',
            'is_required' => $sampleRequired, 'automation_key' => 'ART_SAMPLE_APPROVAL',
            'document_category_id' => 77, 'document_required_before_completion' => true,
            'sort_order' => 1,
        ]);
        $sampleItem->setRelation('documentCategory', new MasterRecord(['name' => 'Sample Approval']));
        $pack = new TaskPack();
        $pack->forceFill(['id' => 401]);
        $pack->setRelation('items', collect([$sampleItem]));
        $phase = new WorkflowPhase([
            'id' => 302, 'name' => 'Artwork', 'short_name' => 'Artwork',
            'sequence' => 2, 'task_pack_id' => 401,
        ]);
        $phase->setRelation('taskPack', $pack);

        $task = new Task([
            'id' => 803, 'flow_job_id' => 901, 'workflow_phase_id' => 302,
            'title' => 'Sample Approval (when required)', 'task_pack_task_id' => 502,
            'status' => $sampleStatus, 'document_category_id' => 77,
            'document_requirement_source' => 'task_pack',
            'progress' => $sampleStatus === 'Completed' ? 100 : 50,
        ]);
        $task->forceFill(['id' => 803]);
        $task->setRelation('setupTemplate', $sampleItem);
        // This is a database-free fixture: never lazy-load the legacy template
        // when the phase presenter evaluates task sort order.
        $task->setRelation('template', null);
        $task->setRelation('documents', collect());
        $task->setRelation('links', collect());

        $order = new FlowJob(['status' => 'In Progress', 'workflow_phase_id' => 302]);
        $order->setRelation('phase', $phase);
        $order->setRelation('tasks', collect([$task]));
        $order->setRelation('documents', collect());

        return [$order, $task];
    }

    public function test_completed_sample_does_not_require_a_second_document_to_release_artwork(): void
    {
        [$order] = $this->artworkWithSample('Completed');

        $this->assertCount(0, JobDetailPresenter::missingCurrentDocuments($order));
        $this->assertCount(0, JobDetailPresenter::blockers($order));
    }

    public function test_unfinished_sample_still_blocks_the_current_stage(): void
    {
        [$order] = $this->artworkWithSample('Sample: Awaiting Shipment');

        $this->assertCount(1, JobDetailPresenter::missingCurrentDocuments($order));
        $this->assertNotEmpty(JobDetailPresenter::blockers($order));
    }

    public function test_completed_non_sample_required_document_is_still_a_blocker(): void
    {
        [$order, $task] = $this->artworkWithSample('Completed', true);
        $task->title = 'Required approval document';
        $task->setupTemplate->automation_key = 'OTHER_REQUIRED_DOCUMENT';

        $this->assertCount(1, JobDetailPresenter::missingCurrentDocuments($order));
    }

    private function align(OrderWorkflowSection $section, int $currentPhaseId): void
    {
        (new ReflectionMethod($section, 'alignOverviewWithCurrentStage'))->invoke($section, $currentPhaseId);
    }

    public function test_runtime_stage_transition_selects_new_stage_before_task_loading(): void
    {
        $section = new OrderWorkflowSection();
        $section->overviewPhaseId = 302;
        $section->lastOverviewWorkflowPhaseId = 302;
        $section->expandedResourceTaskId = 803;

        $this->align($section, 303);

        $this->assertSame(303, $section->overviewPhaseId);
        $this->assertSame(303, $section->lastOverviewWorkflowPhaseId);
        $this->assertNull($section->expandedResourceTaskId);
    }

    public function test_manual_historical_stage_selection_is_preserved(): void
    {
        $section = new OrderWorkflowSection();
        $section->overviewPhaseId = 301;
        $section->lastOverviewWorkflowPhaseId = 302;

        $this->align($section, 303);

        $this->assertSame(301, $section->overviewPhaseId);
        $this->assertSame(303, $section->lastOverviewWorkflowPhaseId);
    }

    public function test_open_editor_defers_stage_navigation_until_safe(): void
    {
        $section = new OrderWorkflowSection();
        $section->overviewPhaseId = 302;
        $section->lastOverviewWorkflowPhaseId = 302;
        $section->showOrderWorkflowActionModal = true;

        $this->align($section, 303);
        $this->assertSame(302, $section->overviewPhaseId);
        $this->assertSame(302, $section->lastOverviewWorkflowPhaseId);

        $section->showOrderWorkflowActionModal = false;
        $this->align($section, 303);
        $this->assertSame(303, $section->overviewPhaseId);
    }
}
