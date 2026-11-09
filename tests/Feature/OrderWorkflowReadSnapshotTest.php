<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\OrderWorkflowReadSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Queries\Orders\OrderWorkflowDisplayQuery;
use App\Services\OrderWorkflowBindingService;
use App\Services\Orders\OrderWorkflowSnapshotService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The snapshot is a derived read model, never a Task mutation/authorization source. */
class OrderWorkflowReadSnapshotTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('flowtrack.workflow_display_query_enabled', true);
        config()->set('flowtrack.workflow_read_snapshot_writes_enabled', true);
        config()->set('flowtrack.workflow_read_snapshot_enabled', true);
    }

    /** @return array{FlowJob, Task, WorkflowPhase, User} */
    private function fixture(): array
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Snapshot QA', 'code' => 'SNAP-'.uniqid(), 'is_active' => true]);
        $template = WorkflowTemplate::create([
            'workspace_id' => 1, 'name' => 'Snapshot QA', 'code' => 'SNAP-'.uniqid(),
            'applies_to' => 'orders', 'client_availability' => 'all', 'is_active' => true,
            'is_default' => false, 'version' => 1,
        ]);
        $runtime = Workflow::create([
            'id' => $template->id, 'name' => $template->name,
            'slug' => 'snapshot-'.uniqid(), 'is_active' => true,
        ]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $runtime->id, 'workflow_template_id' => $template->id,
            'sequence' => 1, 'name' => 'Artwork', 'short_name' => 'Artwork',
            'allow_job_start' => true, 'is_active' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'SNAP-ORDER-'.uniqid(), 'client_id' => $client->id,
            'workflow_id' => $runtime->id, 'workflow_phase_id' => $phase->id,
            'created_by' => $admin->id, 'title' => 'Snapshot Order', 'status' => 'Active',
        ]);
        $task = Task::create([
            'task_number' => 'SNAP-TASK-'.uniqid(), 'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id, 'title' => 'Review artwork',
            'status' => 'Ready', 'progress' => 0, 'priority' => 'Medium',
            'assignee_id' => $admin->id,
        ]);
        return [$job->fresh(), $task, $phase, $admin];
    }

    public function test_snapshot_hit_reuses_compact_tasks_without_loading_all_task_rows(): void
    {
        [$job, $task, $phase, $admin] = $this->fixture();
        $service = app(OrderWorkflowSnapshotService::class);
        $this->assertTrue($service->publishIfCurrent((int) $job->id));
        $snapshot = OrderWorkflowReadSnapshot::query()->where('flow_job_id', $job->id)->firstOrFail();
        $this->assertSame((int) $job->fresh()->workflow_display_version, (int) $snapshot->source_version);
        $this->assertStringNotContainsString('signed_url', $snapshot->projection_json);
        $this->assertStringNotContainsString('financial', $snapshot->projection_json);

        $phases = app(OrderWorkflowBindingService::class)->publishedPhasesForRead((int) $job->workflow_id);
        $reader = app(OrderWorkflowDisplayQuery::class);
        $reader->preferDisplayPhase($job, (int) $phase->id, false);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $projection = $reader->loadForAuthorizedOrder($job, $admin, $phases);
        $sql = collect(DB::connection()->getQueryLog())->pluck('query')->implode(' ');
        DB::connection()->disableQueryLog();
        $this->assertSame((int) $task->id, $projection->tasksByPhase->get((int) $phase->id)->first()->id);
        $this->assertSame('Review artwork', $projection->tasks->first()->title);
        $this->assertSame($admin->name, $projection->tasks->first()->assignee->name);
        $this->assertDoesNotMatchRegularExpression('/from ["`]?tasks["`]?/i', $sql);
    }

    public function test_transaction_updates_bump_version_once_and_older_builder_cannot_publish(): void
    {
        [$job, $task] = $this->fixture();
        $snapshots = app(OrderWorkflowSnapshotService::class);
        $this->assertTrue($snapshots->publishIfCurrent((int) $job->id));
        $oldVersion = (int) $job->fresh()->workflow_display_version;
        DB::transaction(static function () use ($task): void {
            $task->update(['status' => 'In Progress']);
            $task->update(['progress' => 25]);
        });
        $this->assertSame($oldVersion + 1, (int) $job->fresh()->workflow_display_version);
        $this->assertFalse($snapshots->publishIfCurrent((int) $job->id, $oldVersion));
        $this->assertNull($snapshots->forAuthorizedOrder($job->fresh(), User::query()->findOrFail($job->created_by),
            app(OrderWorkflowBindingService::class)->publishedPhasesForRead((int) $job->workflow_id)));
        $this->assertTrue($snapshots->publishIfCurrent((int) $job->id));
        $this->assertSame('In Progress', json_decode(OrderWorkflowReadSnapshot::where('flow_job_id', $job->id)
            ->value('projection_json'), true)['tasks'][0]['attributes']['status']);
    }

    public function test_restricted_users_malformed_data_and_definition_changes_use_live_fallback(): void
    {
        [$job, $task, $phase, $admin] = $this->fixture();
        $snapshots = app(OrderWorkflowSnapshotService::class);
        $this->assertTrue($snapshots->publishIfCurrent((int) $job->id));
        $phases = app(OrderWorkflowBindingService::class)->publishedPhasesForRead((int) $job->workflow_id);
        $restricted = User::factory()->create(['is_super_admin' => false, 'is_active' => true]);
        $this->assertNull($snapshots->forAuthorizedOrder($job, $restricted, $phases));

        OrderWorkflowReadSnapshot::where('flow_job_id', $job->id)->update(['projection_json' => '{broken']);
        $this->assertNull($snapshots->forAuthorizedOrder($job, $admin, $phases));
        $this->assertTrue($snapshots->publishIfCurrent((int) $job->id));
        $phase->update(['name' => 'Artwork Revision']);
        $freshPhases = $phase->newQuery()->where('workflow_template_id', $job->workflow_id)
            ->with(['taskPack.items.documentCategory'])->get();
        $this->assertNull($snapshots->forAuthorizedOrder($job->fresh(), $admin, $freshPhases));
    }

    public function test_a_malformed_task_card_fails_closed_to_the_authorized_live_reader(): void
    {
        [$job, $task, $phase, $admin] = $this->fixture();
        $snapshots = app(OrderWorkflowSnapshotService::class);
        $this->assertTrue($snapshots->publishIfCurrent((int) $job->id));
        $row = OrderWorkflowReadSnapshot::where('flow_job_id', $job->id)->firstOrFail();
        $payload = json_decode((string) $row->projection_json, true);
        unset($payload['tasks'][0]['attributes']['status']);
        $row->update(['projection_json' => json_encode($payload)]);
        $phases = app(OrderWorkflowBindingService::class)->publishedPhasesForRead((int) $job->workflow_id);
        $this->assertNull($snapshots->forAuthorizedOrder($job->fresh(), $admin, $phases));
    }

    public function test_unrelated_order_edit_does_not_invalidate_workflow_snapshot(): void
    {
        [$job] = $this->fixture();
        $version = (int) $job->fresh()->workflow_display_version;
        $job->update(['title' => 'Unrelated title update']);
        $this->assertSame($version, (int) $job->fresh()->workflow_display_version);
    }
}
