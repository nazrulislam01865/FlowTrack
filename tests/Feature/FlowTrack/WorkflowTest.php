<?php

use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Business\Services\WorkflowEngine;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use Database\Seeders\FlowTrack\FlowTrackSeeder;

beforeEach(function () {
    $this->seed(FlowTrackSeeder::class);
});

test('workspace manager can open the exact database-backed template', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->get(route('flowtrack.dashboard'))
        ->assertOk()
        ->assertViewIs('flowtrack-template');
});

test('workspace manager can load the exact-template bootstrap data', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->getJson(route('flowtrack.template-api.bootstrap'))
        ->assertOk()
        ->assertJsonStructure(['currentUser', 'users', 'clients', 'jobs', 'tasks', 'workflows', 'masterData', 'taskPacks']);
});

test('workspace manager can create a client from the exact template API', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->postJson(route('flowtrack.template-api.clients.store'), [
            'name' => 'New Dynamic Client',
            'country' => 'United States',
            'contact_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'account_manager_id' => $manager->id,
            'preferred_language' => 'en',
            'outstanding_balance' => 0,
        ])
        ->assertCreated()
        ->assertJsonPath('client.name', 'New Dynamic Client');

    $this->assertDatabaseHas('clients', ['name' => 'New Dynamic Client']);
});

test('members cannot open jobs outside their project assignment', function () {
    $member = User::where('email', 'tang.rui@flowtrack.cn')->firstOrFail();
    $job = FlowJob::query()->firstOrFail();

    $this->actingAs($member)
        ->get(route('flowtrack.jobs.show', $job))
        ->assertNotFound();
});

test('creating a job activates its starting phase task pack', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $workspaceId = $manager->currentWorkspaceId();
    $workflow = WorkflowTemplate::where('workspace_id', $workspaceId)->where('is_default', true)->with('phases')->firstOrFail();
    $phase = $workflow->phases->sortBy('sequence')->first();
    $client = Client::where('workspace_id', $workspaceId)->firstOrFail();
    $currency = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'currency')->where('code', 'USD')->firstOrFail();
    $priority = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'priority')->where('code', 'MED')->firstOrFail();

    $job = app(CreateJobAction::class)->execute(new CreateJobData(
        workspaceId: $workspaceId,
        clientId: $client->id,
        workflowTemplateId: $workflow->id,
        startPhaseId: $phase->id,
        productId: null,
        productCategoryId: null,
        currencyId: $currency->id,
        priorityId: $priority->id,
        ownerId: $manager->id,
        coordinatorId: $manager->id,
        title: 'Automated task-pack test job',
        quantity: 100,
        quotedAmount: 500,
        deliveryDate: now()->addMonth()->toDateString(),
        startReason: null,
        memberIds: [],
        draft: false,
    ), $manager);

    expect($job->tasks()->count())->toBe(5)
        ->and($job->phaseHistory()->count())->toBe($workflow->phases()->count());
});

test('a required workflow phase cannot be skipped', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $job = FlowJob::query()->whereHas('currentPhase', fn ($query) => $query->where('sequence', 1))->with('workflow.phases')->first();

    if (! $job) {
        $this->markTestSkipped('Seeded portfolio has no job in the first phase.');
    }

    $shipment = $job->workflow->phases->firstWhere('short_name', 'Shipment');

    expect(fn () => app(WorkflowEngine::class)->move($job, $shipment, $manager, 'Attempting to skip production'))
        ->toThrow(\DomainException::class, 'Production cannot be skipped.');
});

test('task and job progress are recalculated from task status', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $task = \App\Modules\OrderFlow\Data\Models\FlowTask::query()
        ->whereNull('dependency_id')
        ->with('job')
        ->firstOrFail();
    $completed = MasterRecord::query()
        ->where('workspace_id', $manager->currentWorkspaceId())
        ->where('type', 'task_status')
        ->where('code', 'CMP')
        ->firstOrFail();

    $this->actingAs($manager)
        ->putJson(route('flowtrack.template-api.tasks.update', $task), [
            'task_status_id' => $completed->id,
            'assignee_id' => $task->assignee_id,
            'progress' => 0,
            'attention_required' => false,
            'completed_checklist_ids' => [],
            'comment' => 'Completed for automatic progress test.',
        ])
        ->assertOk()
        ->assertJsonPath('task.progress', 100);

    expect($task->fresh()->progress)->toBe(100)
        ->and($task->job->fresh()->progress)->toBeGreaterThanOrEqual(0);
});

test('master data codes are generated per section and remain immutable', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $response = $this->actingAs($manager)
        ->postJson(route('flowtrack.template-api.master-data.store'), [
            'type' => 'product_category',
            'name' => 'Drinkware',
            'description' => 'Reusable bottles and cups',
            'status' => 'active',
            'sort_order' => 9,
            'code' => 'MANUAL-CODE-MUST-BE-IGNORED',
        ])
        ->assertCreated()
        ->assertJsonPath('record.code', 'CAT-009');

    $recordId = $response->json('record.id');

    $this->actingAs($manager)
        ->putJson(route('flowtrack.template-api.master-data.update', $recordId), [
            'type' => 'product_category',
            'name' => 'Drinkware & Bottles',
            'description' => 'Updated description',
            'status' => 'active',
            'sort_order' => 9,
            'code' => 'CHANGED',
        ])
        ->assertOk()
        ->assertJsonPath('record.code', 'CAT-009');

    $this->assertDatabaseHas('master_records', [
        'id' => $recordId,
        'code' => 'CAT-009',
        'name' => 'Drinkware & Bottles',
    ]);
});

test('a complete task pack can be created and updated from one form', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $workspaceId = $manager->currentWorkspaceId();
    $priority = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'priority')->where('code', 'HIG')->firstOrFail();
    $department = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'department')->firstOrFail();

    $response = $this->actingAs($manager)
        ->postJson(route('flowtrack.template-api.task-packs.store'), [
            'name' => 'Quality Release',
            'description' => 'Final quality and release activities',
            'is_active' => true,
            'items' => [
                [
                    'title' => 'Inspect finished goods',
                    'description' => 'Check quantity, colour and workmanship.',
                    'default_assignee_id' => null,
                    'default_department_id' => $department->id,
                    'priority_id' => $priority->id,
                    'due_offset_days' => 1,
                    'is_required' => true,
                ],
                [
                    'title' => 'Approve release',
                    'description' => null,
                    'default_assignee_id' => $manager->id,
                    'default_department_id' => null,
                    'priority_id' => $priority->id,
                    'due_offset_days' => 2,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('taskPack.code', 'TPK-008')
        ->assertJsonCount(2, 'taskPack.items');

    $pack = $response->json('taskPack');

    $this->actingAs($manager)
        ->putJson(route('flowtrack.template-api.task-packs.update', $pack['id']), [
            'name' => 'Quality Release Updated',
            'description' => 'Updated reusable sequence',
            'is_active' => true,
            'items' => [
                [
                    'id' => $pack['items'][1]['id'],
                    'title' => 'Approve final release',
                    'description' => null,
                    'default_assignee_id' => $manager->id,
                    'default_department_id' => null,
                    'priority_id' => $priority->id,
                    'due_offset_days' => 1,
                    'is_required' => true,
                ],
                [
                    'id' => $pack['items'][0]['id'],
                    'title' => 'Inspect finished goods',
                    'description' => 'Check quantity, colour and workmanship.',
                    'default_assignee_id' => null,
                    'default_department_id' => $department->id,
                    'priority_id' => $priority->id,
                    'due_offset_days' => 2,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('taskPack.code', 'TPK-008')
        ->assertJsonPath('taskPack.name', 'Quality Release Updated')
        ->assertJsonPath('taskPack.items.0.title', 'Approve final release');
});
