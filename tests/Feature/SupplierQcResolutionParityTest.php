<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\TaskPackItem;
use App\Services\SupplierBridge\SupplierWorkflowProjection;
use Tests\TestCase;

/** Guard the actual conditional QC issue handoff without running database queries. */
class SupplierQcResolutionParityTest extends TestCase
{
    private function scenario(string $checkStatus = 'Waiting for QC Issue Resolution',
        string $issueStatus = 'QC Issue Reported', int $issuePhaseId = 41): array
    {
        $job = (new FlowJob)->forceFill([
            'id' => 101, 'workflow_phase_id' => 41, 'status' => 'In Progress',
        ]);
        $check = (new Task)->forceFill([
            'id' => 401, 'flow_job_id' => 101, 'workflow_phase_id' => 41,
            'task_pack_task_id' => 1001, 'title' => 'Custom check label', 'status' => $checkStatus,
        ]);
        $check->setRelation('setupTemplate', (new TaskPackItem)->forceFill([
            'id' => 1001, 'automation_key' => 'QC_CHECK', 'is_required' => true,
        ]));
        $issue = (new Task)->forceFill([
            'id' => 402, 'flow_job_id' => 101, 'workflow_phase_id' => $issuePhaseId,
            'task_pack_task_id' => 1002, 'title' => 'Corrective actions', 'status' => $issueStatus,
        ]);
        $issue->setRelation('setupTemplate', (new TaskPackItem)->forceFill([
            'id' => 1002, 'automation_key' => 'QC_ISSUE', 'is_required' => false,
        ]));
        $job->setRelation('tasks', collect([$check, $issue]));
        return [$job, $check, $issue];
    }

    public function test_reported_qc_issue_unblocks_supplier_resolution_even_with_custom_task_title(): void
    {
        [$job, , $issue] = $this->scenario();
        $this->assertTrue(SupplierWorkflowProjection::activeQcResolution($job, $issue));
        $this->assertSame('active', SupplierWorkflowProjection::taskMode($job, $issue));
        $this->assertContains('resolve', SupplierWorkflowProjection::decisions('QC_ISSUE'));
    }

    public function test_unreported_qc_issue_must_remain_locked(): void
    {
        foreach ([
            ['Ready', 'QC Issue Reported', 41],
            ['Waiting for QC Issue Resolution', 'Not Started', 41],
            ['Waiting for QC Issue Resolution', 'QC Issue Reported', 42],
        ] as [$checkStatus, $issueStatus, $phase]) {
            [$job, , $issue] = $this->scenario($checkStatus, $issueStatus, $phase);
            $this->assertFalse(SupplierWorkflowProjection::activeQcResolution($job, $issue));
        }
    }

    public function test_closed_orders_and_completed_issue_cannot_reopen(): void
    {
        [$job, , $issue] = $this->scenario();
        $job->status = 'Completed';
        $this->assertFalse(SupplierWorkflowProjection::activeQcResolution($job, $issue));
        $job->status = 'In Progress';
        $issue->completed_at = now();
        $this->assertFalse(SupplierWorkflowProjection::activeQcResolution($job, $issue));
    }

}
