<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\WorkflowPhase;
use App\Queries\Orders\OrderWorkflowDisplayQuery;
use App\Queries\Orders\WorkflowDisplayProjection;
use Illuminate\Support\Collection;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Query-free tests of the request-local lookup contract. Integration, role,
 * SQL-count and workflow action tests remain separate release gates.
 */
class OrderWorkflowDisplayQueryContractTest extends TestCase
{
    /** @return array{0: FlowJob, 1: WorkflowPhase, 2: OrderWorkflowDisplayQuery} */
    private function seededProjection(int $taskCount): array
    {
        $order = new FlowJob;
        $order->id = 91;
        $phase = new WorkflowPhase;
        $phase->id = 31;
        $phase->setRelation('taskPack', null);
        $tasks = collect();
        for ($id = 1; $id <= $taskCount; $id++) {
            $task = new Task;
            $task->id = $id;
            $task->flow_job_id = 91;
            $task->workflow_phase_id = 31;
            $task->setRelation('setupTemplate', null);
            $task->setRelation('template', null);
            $tasks->push($task);
        }
        $order->setRelation('tasks', $tasks);
        $documents = collect();
        foreach ([1, 1, 2] as $i => $taskId) {
            $document = new Document;
            $document->id = $i + 1;
            $document->task_id = $taskId;
            $documents->push($document);
        }
        $order->setRelation('documents', $documents);
        $query = app(OrderWorkflowDisplayQuery::class);
        $projection = new WorkflowDisplayProjection(
            collect([$phase]),
            $tasks->keyBy('id'),
            $tasks->groupBy('workflow_phase_id'),
            $tasks,
        );
        $loaded = (new ReflectionProperty(OrderWorkflowDisplayQuery::class, 'loaded'))->getValue($query);
        $loaded[$order] = [
            'source' => $tasks,
            'count' => $tasks->count(),
            'projection' => $projection,
            'phaseTasks' => [],
            'documentSource' => $documents,
            'documentCount' => $documents->count(),
            'documentsByTask' => $documents->groupBy('task_id'),
        ];

        return [$order, $phase, $query];
    }

    public function test_id_and_phase_maps_preserve_order_and_do_not_repeat_phase_processing(): void
    {
        [$order, $phase, $query] = $this->seededProjection(120);
        $this->assertSame(84, $query->taskById($order, 84)->id);
        $this->assertCount(120, $query->tasksForPhase($order, 31));
        $this->assertSame([1, 2], $query->documentsForTask($order, 1)->pluck('id')->all());
        $this->assertCount(0, $query->documentsForTask($order, 120));
        $builds = 0;
        for ($i = 0; $i < 20; $i++) {
            $result = $query->rememberedPhaseTasks($order, (int) $phase->id, function () use (&$builds): Collection {
                $builds++;
                return collect([1, 2, 3]);
            });
            $this->assertSame([1, 2, 3], $result->all());
        }
        $this->assertSame(1, $builds);
    }

    public function test_replacing_or_expanding_task_relation_invalidates_the_maps(): void
    {
        [$order, , $query] = $this->seededProjection(2);
        $this->assertNotNull($query->taskById($order, 1));

        $order->setRelation('tasks', collect());
        $this->assertNull($query->taskById($order, 1));
        $this->assertNull($query->tasksForPhase($order, 31));

        [$another, , $anotherQuery] = $this->seededProjection(2);
        $another->tasks->push(new Task);
        $this->assertNull($anotherQuery->taskById($another, 1));
    }

    public function test_feature_flags_are_off_by_default_and_existing_reader_remains(): void
    {
        $config = file_get_contents(config_path('flowtrack.php'));
        $legacy = file_get_contents(app_path('Services/LegacyJobService.php'));
        $query = file_get_contents(app_path('Queries/Orders/OrderWorkflowDisplayQuery.php'));

        $this->assertStringContainsString("env('FLOWTRACK_WORKFLOW_DISPLAY_QUERY_ENABLED', false)", $config);
        $this->assertStringContainsString("config('flowtrack.workflow_display_query_enabled', false)", $legacy);
        $this->assertStringContainsString("if (! \$useDisplayQuery)", $legacy);
        $this->assertStringContainsString('applyTaskScope($query, $viewer)', $query);
        $this->assertStringContainsString("->where('flow_job_id', (int) \$order->id)", $query);
        $this->assertStringNotContainsString('->save(', $query);
        $this->assertStringNotContainsString('->update(', $query);
    }

    public function test_order_is_authorized_before_maintenance_and_most_modal_data_is_lazily_built(): void
    {
        $section = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $context = file_get_contents(app_path('Services/OrderDetailViewService.php'));
        $authorize = strpos($section, '->scoped($viewer, $this->orderId)');
        $repair = strpos($section, 'syncSingleActiveOrder($this->orderId)');

        $this->assertNotFalse($authorize);
        $this->assertNotFalse($repair);
        $this->assertLessThan($repair, $authorize);
        $this->assertStringContainsString('$modalTaskId === null', $context);
        $this->assertStringContainsString('$this->showOrderWorkflowActionModal ? (int) $this->orderWorkflowActionTaskId : 0', $section);
    }

    public function test_display_phase_preference_and_document_reindex_are_request_local(): void
    {
        [$order, , $query] = $this->seededProjection(2);
        // Standalone callers that did not provide a hint retain all document
        // resources; a Livewire card request can opt into one stage.
        $this->assertTrue($query->displayPreference($order)['fullDocuments']);
        $query->preferDisplayPhase($order, 31, false);
        $this->assertSame(31, $query->displayPreference($order)['phase']);
        $this->assertFalse($query->displayPreference($order)['fullDocuments']);

        $replacement = collect([new Document]);
        $replacement->first()->id = 99;
        $replacement->first()->task_id = 2;
        $order->setRelation('documents', $replacement);
        $query->indexLoadedDocuments($order);
        $this->assertSame([99], $query->documentsForTask($order, 2)->pluck('id')->all());
        $this->assertCount(0, $query->documentsForTask($order, 1));

        $another = new FlowJob;
        $another->id = $order->id;
        $this->assertTrue($query->displayPreference($another)['fullDocuments']);
    }
}
