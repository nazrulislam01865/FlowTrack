<?php

namespace Tests\Unit;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\SupplierBridge\SupplierWorkflowProjection;
use PHPUnit\Framework\TestCase;

final class SupplierSampleActionModeTest extends TestCase
{
    private function sampleOrder(): array
    {
        $phase = new WorkflowPhase();
        $phase->setRawAttributes(['id' => 12, 'sequence' => 2]);
        $pack = new TaskPack();
        $pack->setRawAttributes(['id' => 20]);
        $templates = collect();
        $tasks = collect();

        foreach ([
            ['ART_PREPARE_UPLOAD', 'Completed', true],
            ['ART_CLIENT_ERP_DECISION', 'Waiting for Sample Approval', true],
            ['ART_SAMPLE_APPROVAL', 'Sample: Supplier Preparing', false],
        ] as $i => [$key, $status, $required]) {
            $template = new TaskPackItem();
            $template->setRawAttributes(['id' => 200 + $i, 'task_pack_id' => 20,
                'sort_order' => $i + 1, 'automation_key' => $key, 'is_required' => $required]);
            $templates->push($template);
            $task = new Task();
            $task->setRawAttributes(['id' => 500 + $i, 'flow_job_id' => 3,
                'workflow_phase_id' => 12, 'task_pack_task_id' => 200 + $i,
                'title' => $key === 'ART_SAMPLE_APPROVAL' ? 'Sample Approval (when required)' : $key,
                'status' => $status, 'completed_at' => $i === 0 ? '2026-11-10 08:00:00' : null]);
            $task->setRelation('setupTemplate', $template);
            $tasks->push($task);
        }
        $pack->setRelation('items', $templates);
        $phase->setRelation('taskPack', $pack);
        $workflow = new Workflow();
        $workflow->setRelation('phases', collect([$phase]));
        $order = new FlowJob();
        $order->setRawAttributes(['id' => 3, 'workflow_phase_id' => 12, 'status' => 'In Progress']);
        $order->setRelation('workflow', $workflow);
        $order->setRelation('phase', $phase);
        $order->setRelation('tasks', $tasks);
        return [$order, $tasks];
    }

    public function test_supplier_can_confirm_preparation_and_revisions_only_while_client_is_waiting(): void
    {
        [$order, $tasks] = $this->sampleOrder();
        $this->assertSame('active', SupplierWorkflowProjection::taskMode($order, $tasks[2]));
        $this->assertSame(['sample_prepared'], SupplierWorkflowProjection::decisionsForStatus('ART_SAMPLE_APPROVAL', $tasks[2]->status));

        $tasks[2]->status = 'Sample: Supplier Revision';
        $this->assertSame('active', SupplierWorkflowProjection::taskMode($order, $tasks[2]));

        foreach (['Sample: Awaiting Receipt', 'Sample: Awaiting Decision', 'Sample: Awaiting Shipment'] as $status) {
            $tasks[2]->status = $status;
            $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[2]));
            $this->assertSame([], SupplierWorkflowProjection::decisionsForStatus('ART_SAMPLE_APPROVAL', $status));
        }
    }

    public function test_supplier_cannot_bypass_unfinished_artwork_or_a_missing_client_sample_decision(): void
    {
        [$order, $tasks] = $this->sampleOrder();
        $tasks[0]->status = 'Ready';
        $tasks[0]->completed_at = null;
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[2]));
        $tasks[0]->status = 'Completed';
        $tasks[0]->completed_at = '2026-11-10 08:00:00';

        $tasks[1]->status = 'Waiting for Client';
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[2]));
        $tasks[1]->status = 'Waiting for Sample Approval';

        $order->workflow_phase_id = 13;
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[2]));
        $order->workflow_phase_id = 12;
        $order->status = 'Cancelled';
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[2]));
    }
}
