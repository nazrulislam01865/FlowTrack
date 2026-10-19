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

final class SupplierWorkflowActionModeTest extends TestCase
{
    /** Verify a ready supplier task follows the canonical preceding-task gate. */
    public function test_first_open_required_artwork_task_is_actionable_only_after_prior_tasks_finish(): void
    {
        $phase = new WorkflowPhase();
        $phase->setRawAttributes(['id' => 322, 'sequence' => 2]);
        $pack = new TaskPack();
        $pack->setRawAttributes(['id' => 12]);
        $templates = collect();
        $tasks = collect();
        foreach ([1, 2, 3, 4] as $index) {
            $template = new TaskPackItem();
            $template->setRawAttributes(['id' => 200 + $index, 'sort_order' => $index,
                'is_required' => true, 'automation_key' => $index === 4
                    ? 'ART_CLIENT_ERP_DECISION' : 'ART_INTERNAL_REVIEW']);
            $templates->push($template);
            $task = new Task();
            $task->setRawAttributes(['id' => 12937 + $index, 'flow_job_id' => 329,
                'workflow_phase_id' => 322, 'task_pack_task_id' => 200 + $index,
                'status' => $index === 4 ? 'Ready' : 'Completed',
                'completed_at' => $index === 4 ? null : '2026-10-12 18:00:00']);
            $task->setRelation('setupTemplate', $template);
            $tasks->push($task);
        }
        $pack->setRelation('items', $templates);
        $phase->setRelation('taskPack', $pack);
        $workflow = new Workflow();
        $workflow->setRelation('phases', collect([$phase]));
        $order = new FlowJob();
        $order->setRawAttributes(['id' => 329, 'workflow_phase_id' => 322, 'status' => 'In Progress']);
        $order->setRelation('workflow', $workflow);
        $order->setRelation('phase', $phase);
        $order->setRelation('tasks', $tasks);

        $this->assertSame('active', SupplierWorkflowProjection::taskMode($order, $tasks[3]));
        $this->assertSame('done', SupplierWorkflowProjection::taskMode($order, $tasks[0]));

        // Order 329: ERP upload has been saved, but client approval is still
        // pending. The assigned supplier must be able to record that decision.
        $tasks[3]->status = 'Waiting for Client';
        $this->assertSame('active', SupplierWorkflowProjection::taskMode($order, $tasks[3]));
        $this->assertSame(['confirm', 'sample', 'revise'],
            SupplierWorkflowProjection::decisionsForStatus('ART_CLIENT_ERP_DECISION', $tasks[3]->status));

        // The ERP decision must NOT reopen while Sample Approval is pending.
        $tasks[3]->status = 'Waiting for Sample Approval';
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[3]));
        $this->assertSame([], SupplierWorkflowProjection::decisionsForStatus('ART_CLIENT_ERP_DECISION', $tasks[3]->status));

        // Nor may an uncompleted predecessor or a different stage be bypassed.
        $tasks[3]->status = 'Waiting for Client';
        $tasks[2]->setRawAttributes(['id' => 12940, 'flow_job_id' => 329,
            'workflow_phase_id' => 322, 'task_pack_task_id' => 203,
            'status' => 'Ready', 'completed_at' => null]);
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[3]));
        $tasks[2]->status = 'Completed';
        $tasks[2]->completed_at = '2026-10-12 18:00:00';
        $order->workflow_phase_id = 323;
        $this->assertSame('locked', SupplierWorkflowProjection::taskMode($order, $tasks[3]));
    }
}
