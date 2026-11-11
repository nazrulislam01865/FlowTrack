<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Queries\Orders\OrderPhaseSummaryQuery;
use App\Queries\Orders\OrderWorkflowDisplayQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Scoped reads must not change any saved Task, phase, or workflow action. */
class OrderThreeLevelScopedQueryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_reader_fetches_only_selected_phase_cards_and_preserves_other_stage_counts(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create([
            'name' => 'Three-level read QA', 'code' => 'THREE-'.uniqid(), 'is_active' => true,
        ]);
        $workflow = Workflow::create([
            'name' => 'Scoped read QA', 'slug' => 'three-'.uniqid(), 'is_active' => true,
        ]);
        $first = WorkflowPhase::create([
            'workflow_id' => $workflow->id, 'name' => 'Artwork', 'short_name' => 'Artwork',
            'sequence' => 1, 'is_active' => true, 'allow_job_start' => true,
        ]);
        $second = WorkflowPhase::create([
            'workflow_id' => $workflow->id, 'name' => 'Production', 'short_name' => 'Production',
            'sequence' => 2, 'is_active' => true, 'allow_job_start' => false,
        ]);
        $job = FlowJob::create([
            'job_number' => 'THREE-'.uniqid(), 'title' => 'Read QA',
            'client_id' => $client->id, 'workflow_id' => $workflow->id,
            'workflow_phase_id' => $second->id, 'created_by' => $viewer->id,
            'status' => 'Active',
        ]);
        $firstComplete = $this->makeTask($job, $first, 'Completed');
        $secondOpen = $this->makeTask($job, $second, 'Not Started');
        $secondDone = $this->makeTask($job, $second, 'Completed');

        $phases = collect([$first, $second]);
        foreach ($phases as $phase) {
            $phase->setRelation('taskPack', null);
        }
        $job->setRelation('phase', $second);
        $display = app(OrderWorkflowDisplayQuery::class);
        $display->preferDisplayPhase($job, (int) $second->id, false);
        $projection = $display->loadForAuthorizedOrder($job, $viewer, $phases, null, [(int) $second->id]);

        $this->assertCount(2, $projection->phases);
        $this->assertSame((int) $second->id, $display->selectedTaskPhaseId($job));
        $this->assertEqualsCanonicalizing([$secondOpen->id, $secondDone->id], $job->tasks->pluck('id')->all());
        $this->assertFalse($job->tasks->contains('id', $firstComplete->id));

        $summaries = app(OrderPhaseSummaryQuery::class)->forAuthorizedOrder(
            $job, $viewer, $phases, (int) $second->id,
        );
        $this->assertSame(1, $summaries[$first->id]['completed']);
        $this->assertSame(1, $summaries[$first->id]['total']);
        $this->assertSame(1, $summaries[$second->id]['completed']);
        $this->assertSame(2, $summaries[$second->id]['total']);
        $this->assertSame(50, $summaries[$second->id]['progress']);

        // The background route reads real saved order tasks, in a separate
        // request, without changing the active workflow or task status.
        config()->set('flowtrack.order_three_level_loading_enabled', true);
        config()->set('flowtrack.workflow_display_query_enabled', true);
        $this->actingAs($viewer)
            ->getJson(route('orders.workflow.stage-prefetch', ['orderId' => $job->id]).'?active_phase_id='.$second->id)
            ->assertOk()
            ->assertJsonPath('order_id', (int) $job->id)
            ->assertJsonPath('active_phase_id', (int) $second->id)
            ->assertJsonCount(1, 'phases')
            ->assertJsonPath('phases.0.phase_id', (int) $first->id)
            ->assertJsonPath('phases.0.tasks.0.id', (int) $firstComplete->id)
            ->assertJsonPath('phases.0.tasks.0.status', 'Completed');

        // Read-only projections cannot alter persisted action/status data.
        $this->assertSame('Not Started', $secondOpen->fresh()->status);
        $this->assertSame('Completed', $firstComplete->fresh()->status);
    }

    public function test_first_response_and_phase_switch_have_distinct_loading_paths(): void
    {
        $component = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $reader = file_get_contents(app_path('Services/LegacyJobService.php'));
        $query = file_get_contents(app_path('Queries/Orders/OrderWorkflowDisplayQuery.php'));
        $stage = file_get_contents(resource_path('views/components/jobs/order-detail/stage-card.blade.php'));

        $this->assertStringContainsString("$".'this->loadWorkflowSection(\'workflow\', \'order\', $orderId);', $component);
        $this->assertStringContainsString("if (! (bool) config('flowtrack.order_three_level_loading_enabled', false)", $component);
        $this->assertStringContainsString('$phaseScoped ? [$displayPhaseId] : null', $reader);
        $this->assertStringContainsString('whereIn(\'workflow_phase_id\', $phaseIds)', $query);
        $this->assertStringContainsString('phaseSummaries', $stage);
        $this->assertStringContainsString('taskResourceDetailsDeferred', $component);
    }

    private function makeTask(FlowJob $order, WorkflowPhase $phase, string $status): Task
    {
        return Task::create([
            'task_number' => 'THREE-TASK-'.uniqid(), 'flow_job_id' => $order->id,
            'workflow_phase_id' => $phase->id, 'title' => 'Test '.uniqid(),
            'status' => $status, 'progress' => $status === 'Completed' ? 100 : 0,
            'priority' => 'Medium',
        ]);
    }
}
