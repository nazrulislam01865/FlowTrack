<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\FlowJob;
use App\Models\FlowJobMember;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackTask;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnassignedOrderTaskClaimAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorised_department_user_can_see_and_claim_an_unassigned_current_stage_task(): void
    {
        $shipmentDepartment = Department::create([
            'code' => 'SHP-CLAIM',
            'name' => 'Shipment Claim Team',
            'is_active' => true,
        ]);
        $accountsDepartment = Department::create([
            'code' => 'ACC-CLAIM',
            'name' => 'Accounts Claim Team',
            'is_active' => true,
        ]);
        $role = $this->assignedTaskRole();
        $shipmentUser = User::factory()->create([
            'role_id' => $role->id,
            'department_id' => $shipmentDepartment->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $accountsUser = User::factory()->create([
            'role_id' => $role->id,
            'department_id' => $accountsDepartment->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);

        $client = Client::create([
            'name' => 'Claimable Shipment Client',
            'code' => 'CLAIM-SHP',
            'is_active' => true,
        ]);
        $workflow = Workflow::create([
            'name' => 'Claimable Shipment Workflow',
            'slug' => 'claimable-shipment-workflow',
            'is_active' => true,
        ]);
        $shipmentPhase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Shipment',
            'short_name' => 'Shipment',
            'allow_job_start' => true,
            'is_active' => true,
        ]);
        $billingPhase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 2,
            'name' => 'Billing',
            'short_name' => 'Billing',
            'allow_job_start' => false,
            'is_active' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'ORDER-CLAIM-SHIPMENT',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $shipmentPhase->id,
            'title' => 'Unassigned shipment order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);

        $pack = TaskPack::create([
            'name' => 'Shipment Claim Pack',
            'slug' => 'shipment-claim-pack',
            'is_active' => true,
        ]);
        $shipmentTemplate = TaskPackTask::create([
            'task_pack_id' => $pack->id,
            'title' => 'Dispatch shipment',
            'sequence' => 1,
            'is_required' => true,
            'default_department_id' => $shipmentDepartment->id,
        ]);
        $futureTemplate = TaskPackTask::create([
            'task_pack_id' => $pack->id,
            'title' => 'Billing follow-up',
            'sequence' => 2,
            'is_required' => true,
            'default_department_id' => $accountsDepartment->id,
        ]);

        $currentTask = Task::create([
            'task_number' => 'TASK-CLAIM-CURRENT',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $shipmentPhase->id,
            'task_pack_task_id' => $shipmentTemplate->id,
            'assignee_id' => null,
            'title' => 'Dispatch shipment',
            'status' => 'Ready',
            'priority' => 'Medium',
        ]);
        $futureTask = Task::create([
            'task_number' => 'TASK-CLAIM-FUTURE',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $billingPhase->id,
            'task_pack_task_id' => $futureTemplate->id,
            'assignee_id' => null,
            'title' => 'Future billing follow-up',
            'status' => 'Not Started',
            'priority' => 'Medium',
        ]);

        $access = app(AccessControlService::class);
        $tasks = app(TaskService::class);

        // The Shipment user is not already assigned to the Order. The current
        // Shipment task is exposed only because its Task Pack department matches.
        $this->assertFalse(FlowJobMember::where('flow_job_id', $job->id)->where('user_id', $shipmentUser->id)->exists());
        $this->assertTrue($tasks->visibleQuery($shipmentUser)->whereKey($currentTask->id)->exists());
        $this->assertFalse($tasks->visibleQuery($shipmentUser)->whereKey($futureTask->id)->exists());
        $this->assertTrue($access->canEditTask($shipmentUser, $currentTask));

        // The first permitted action claims the task and establishes normal
        // assigned-job visibility for all subsequent work.
        $tasks->addComment($currentTask, 'I will handle this shipment.', $shipmentUser);

        $this->assertSame($shipmentUser->id, $currentTask->refresh()->assignee_id);
        $this->assertTrue(FlowJobMember::where('flow_job_id', $job->id)->where('user_id', $shipmentUser->id)->exists());
        $this->assertTrue($currentTask->activities()->where('event', 'task.assignee_auto_assigned')->exists());

        // Membership gained from Shipment must not become a cross-department
        // override. When Billing becomes current, only the Accounts department
        // can claim its unassigned task.
        $job->update(['workflow_phase_id' => $billingPhase->id]);
        $this->assertFalse($tasks->visibleQuery($shipmentUser)->whereKey($futureTask->id)->exists());
        $this->assertFalse($access->canEditTask($shipmentUser, $futureTask));
        $this->assertTrue($tasks->visibleQuery($accountsUser)->whereKey($futureTask->id)->exists());
        $this->assertTrue($access->canEditTask($accountsUser, $futureTask));
    }

    private function assignedTaskRole(): Role
    {
        $role = Role::create([
            'workspace_id' => 1,
            'name' => 'Shipment Claim User',
            'slug' => 'shipment-claim-user',
            'code' => 'SHIPMENT_CLAIM_USER',
            'description' => 'Can work assigned Shipment tasks and claim matching unassigned work.',
            'default_scope' => 'assigned_jobs',
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);

        RoleModuleAccess::create([
            'role_id' => $role->id,
            'module_code' => 'jobs',
            'record_scope' => 'assigned_jobs',
            'actions' => ['view'],
        ]);
        RoleModuleAccess::create([
            'role_id' => $role->id,
            'module_code' => 'tasks',
            'record_scope' => 'assigned_jobs',
            'actions' => ['view', 'edit_own'],
        ]);

        return $role;
    }
}
