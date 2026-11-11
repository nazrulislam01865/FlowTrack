<?php

namespace Tests\Feature;

use App\Livewire\Jobs\OrderWorkflowSection;
use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Support\OrderDetailPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Three-level loading is an opt-in read/display change, never a new workflow writer. */
class OrderThreeLevelLoadingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{FlowJob, User, WorkflowPhase, WorkflowPhase} */
    private function orderWithTwoStages(): array
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Stage Summary QA', 'code' => 'STAGE-'.uniqid(), 'is_active' => true]);
        $template = WorkflowTemplate::create([
            'workspace_id' => 1, 'name' => 'Stage Summary QA', 'code' => 'STAGE-'.uniqid(),
            'applies_to' => 'orders', 'client_availability' => 'all', 'is_active' => true,
            'is_default' => false, 'version' => 1,
        ]);
        $workflow = Workflow::create([
            'id' => $template->id, 'name' => $template->name,
            'slug' => 'stage-'.uniqid(), 'is_active' => true,
        ]);
        $artwork = WorkflowPhase::create([
            'workflow_id' => $workflow->id, 'workflow_template_id' => $template->id,
            'sequence' => 1, 'name' => 'Artwork', 'short_name' => 'Artwork',
            'allow_job_start' => true, 'is_active' => true,
        ]);
        $production = WorkflowPhase::create([
            'workflow_id' => $workflow->id, 'workflow_template_id' => $template->id,
            'sequence' => 2, 'name' => 'Production', 'short_name' => 'Production',
            'allow_job_start' => false, 'is_active' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'STAGE-ORDER-'.uniqid(), 'client_id' => $client->id,
            'workflow_id' => $workflow->id, 'workflow_phase_id' => $artwork->id,
            'created_by' => $admin->id, 'title' => 'Stage summary test', 'status' => 'Active',
        ]);

        return [$job->fresh(), $admin, $artwork, $production];
    }

    private function addTask(FlowJob $job, WorkflowPhase $phase, string $status): Task
    {
        return Task::create([
            'task_number' => 'STAGE-TASK-'.uniqid(),
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'title' => 'Review '.uniqid(),
            'status' => $status,
            'progress' => $status === 'Completed' ? 100 : 0,
            'priority' => 'Medium',
        ]);
    }

    public function test_default_phase_is_active_stage_and_no_duplicate_summary_strip_is_rendered(): void
    {
        [$job, $admin, $artwork, $production] = $this->orderWithTwoStages();
        $job->load(['phase', 'workflow.phases']);

        // No phase manually selected: the existing presenter resolves the
        // canonical current Order stage, not a separate summary-card stage.
        $this->assertSame((int) $artwork->id, (int) OrderDetailPresenter::selectedPhase($job)?->id);

        // Simulate phase advancement without invoking workflow mutations.
        $job->workflow_phase_id = (int) $production->id;
        $job->setRelation('phase', $production);
        $this->assertSame((int) $production->id, (int) OrderDetailPresenter::selectedPhase($job)?->id);

        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $pageData = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $this->assertStringNotContainsString('order-detail.phase-overview', $overview);
        $this->assertStringNotContainsString('stageSummaries', $pageData);
        $this->assertStringContainsString('$this->overviewPhaseId = $afterPhaseId ?: $beforePhaseId ?: null;', $workflow);
    }

    public function test_disabled_flag_preserves_original_workflow_template(): void
    {
        config()->set('flowtrack.order_three_level_loading_enabled', false);
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $component = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $this->assertStringContainsString("config('flowtrack.order_three_level_loading_enabled', false)", $component);
        $this->assertStringContainsString('<livewire:jobs.order-workflow-section', $overview);
        $this->assertStringContainsString('loadWorkflowSection', $component);
        $prefetch = file_get_contents(resource_path('views/livewire/jobs/order-workflow-section.blade.php'));
        $this->assertStringContainsString('data-workflow-stage-prefetch', $prefetch);
        $this->assertStringContainsString('requestIdleCallback(start', $prefetch);
    }

    public function test_resource_toggle_requires_visible_task_in_selected_phase(): void
    {
        [$job, $admin, $artwork, $production] = $this->orderWithTwoStages();
        $task = $this->addTask($job, $artwork, 'Not Started');
        $future = $this->addTask($job, $production, 'Not Started');
        config()->set('flowtrack.order_three_level_loading_enabled', true);
        $this->actingAs($admin);

        // A task in another phase must not be used as a resource lookup key.
        $component = new OrderWorkflowSection();
        $component->mount((int) $job->id);
        $component->ready = true;
        $component->overviewPhaseId = (int) $artwork->id;
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $component->toggleTaskResources((int) $future->id);
    }
}
