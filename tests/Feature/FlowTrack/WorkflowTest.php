<?php

use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Business\Services\WorkflowEngine;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobItem;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

test('workspace manager can create a client with a system generated immutable code', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $response = $this->actingAs($manager)
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

    $code = $response->json('client.code');
    expect($code)->toMatch('/^CL-\d{3,}$/');

    $client = Client::where('name', 'New Dynamic Client')->firstOrFail();
    $client->update(['code' => 'CLIENT-MANUAL-CODE']);
    expect($client->fresh()->code)->toBe($code);

    $this->actingAs($manager)
        ->putJson(route('flowtrack.template-api.clients.update', $client), [
            'name' => 'New Dynamic Client Updated',
            'code' => 'CLIENT-MANUAL-CODE',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    $this->assertDatabaseHas('clients', ['id' => $client->id, 'code' => $code]);
});

test('a Job can be created with multiple product and quantity lines', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $workspaceId = $manager->currentWorkspaceId();
    $workflow = WorkflowTemplate::where('workspace_id', $workspaceId)->where('is_default', true)->with('phases')->firstOrFail();
    $phase = $workflow->phases->sortBy('sequence')->first();
    $client = Client::where('workspace_id', $workspaceId)->firstOrFail();
    $currency = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'currency')->where('code', 'USD')->firstOrFail();
    $priority = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'priority')->where('code', 'MED')->firstOrFail();
    $products = MasterRecord::where('workspace_id', $workspaceId)
        ->where('type', 'product')
        ->whereNotNull('parent_id')
        ->orderBy('parent_id')
        ->get()
        ->groupBy('parent_id')
        ->first(fn ($items) => $items->count() >= 2)
        ->take(2)
        ->values();

    $response = $this->actingAs($manager)
        ->postJson(route('flowtrack.template-api.jobs.store'), [
            'client_id' => $client->id,
            'workflow_template_id' => $workflow->id,
            'start_phase_id' => $phase->id,
            'products' => [
                ['product_id' => $products[0]->id, 'product_category_id' => $products[0]->parent_id, 'quantity' => 120],
                ['product_id' => $products[1]->id, 'product_category_id' => $products[1]->parent_id, 'quantity' => 80],
            ],
            'currency_id' => $currency->id,
            'priority_id' => $priority->id,
            'owner_id' => $manager->id,
            'coordinator_id' => $manager->id,
            'title' => 'Multi-product conference order',
            'quoted_amount' => 0,
            'delivery_date' => now()->addMonth()->toDateString(),
            'member_ids' => [],
            'draft' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('job.productCount', 2)
        ->assertJsonPath('job.qty', 200)
        ->assertJsonCount(2, 'job.products');

    $job = FlowJob::where('code', $response->json('job.id'))->firstOrFail();
    expect($job->quantity)->toBe(200)
        ->and(FlowJobItem::where('flow_job_id', $job->id)->count())->toBe(2);
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
    $task = \App\Modules\OrderFlow\Data\Models\FlowTask::query()
        ->whereNull('dependency_id')
        ->whereNotNull('assignee_id')
        ->with(['job', 'assignee'])
        ->firstOrFail();
    $assignee = $task->assignee;
    $completed = MasterRecord::query()
        ->where('workspace_id', $assignee->currentWorkspaceId())
        ->where('type', 'task_status')
        ->where('code', 'CMP')
        ->firstOrFail();

    $this->actingAs($assignee)
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

test('task blockers dynamically set Job health and create an immutable transition event', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $task = \App\Modules\OrderFlow\Data\Models\FlowTask::query()
        ->whereNull('dependency_id')
        ->with(['job', 'status'])
        ->get()
        ->first(fn ($candidate) => (int) $candidate->workflow_phase_id === (int) $candidate->job?->current_phase_id);
    expect($task)->not->toBeNull();
    $blocked = MasterRecord::query()
        ->where('workspace_id', $task->job->workspace_id)
        ->where('type', 'task_status')
        ->where('code', 'BLK')
        ->firstOrFail();

    app(\App\Modules\OrderFlow\Application\Actions\UpdateTaskAction::class)->execute($task, [
        'task_status_id' => $blocked->id,
        'blocker_reason' => 'Supplier artwork file is missing.',
        'attention_required' => true,
    ], $manager, 'automated_test');

    expect($task->job->fresh()->health)->toBe('blocked')
        ->and($task->job->fresh()->attention_required)->toBeTrue();

    $this->assertDatabaseHas('flow_transition_events', [
        'flow_job_id' => $task->flow_job_id,
        'flow_task_id' => $task->id,
        'transition_type' => 'task.status',
        'to_value' => 'Blocked',
    ]);
});

test('completing every required task makes the phase ready and can automatically activate the next Task Pack', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $workspaceId = $manager->currentWorkspaceId();
    $workflow = WorkflowTemplate::where('workspace_id', $workspaceId)->where('is_default', true)->with('phases')->firstOrFail();
    $phase = $workflow->phases->sortBy('sequence')->first();
    $nextPhase = $workflow->phases->where('sequence', '>', $phase->sequence)->sortBy('sequence')->first();
    $phase->update([
        'document_category_id' => null,
        'requires_approval' => false,
        'auto_advance_on_ready' => true,
    ]);
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
        title: 'Dynamic workflow readiness test',
        quantity: 10,
        quotedAmount: 100,
        deliveryDate: now()->addMonth()->toDateString(),
        startReason: null,
        memberIds: [],
        draft: false,
    ), $manager);

    $completed = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'task_status')->where('code', 'CMP')->firstOrFail();
    foreach ($job->tasks()->where('workflow_phase_id', $phase->id)->orderBy('id')->get() as $task) {
        app(\App\Modules\OrderFlow\Application\Actions\UpdateTaskAction::class)->execute($task, [
            'task_status_id' => $completed->id,
            'attention_required' => false,
        ], $manager, 'automated_test');
    }

    $job->refresh();
    expect($job->current_phase_id)->toBe($nextPhase->id)
        ->and($job->tasks()->where('workflow_phase_id', $nextPhase->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('flow_job_phase_histories', [
        'flow_job_id' => $job->id,
        'workflow_phase_id' => $phase->id,
        'status' => 'completed',
        'readiness_status' => 'complete',
    ]);
    $this->assertDatabaseHas('flow_transition_events', [
        'flow_job_id' => $job->id,
        'transition_type' => 'job.phase',
        'from_value' => $phase->name,
        'to_value' => $nextPhase->name,
        'source' => 'workflow_automation',
    ]);
});

test('current phase Task Packs backfill missing tasks and required deleted tasks are restored', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $job = FlowJob::query()
        ->whereNotNull('current_phase_id')
        ->whereHas('currentPhase.taskPack')
        ->with(['currentPhase.taskPack.items', 'tasks'])
        ->firstOrFail();

    $phase = $job->currentPhase;
    $pack = $phase->taskPack;
    $newItem = $pack->items()->create([
        'title' => 'New required live Task Pack task',
        'description' => 'Added after the Job entered the Phase.',
        'due_offset_days' => 1,
        'is_required' => true,
        'sort_order' => ((int) $pack->items()->max('sort_order')) + 1,
    ]);

    app(\App\Modules\OrderFlow\Business\Services\WorkflowAutomationService::class)
        ->synchronize($job, $manager, 'automated_test', false);

    $generated = $job->tasks()->where('workflow_phase_id', $phase->id)->where('task_pack_item_id', $newItem->id)->first();
    expect($generated)->not->toBeNull();

    $generated->delete();
    expect($generated->fresh())->toBeNull();

    app(\App\Modules\OrderFlow\Business\Services\WorkflowAutomationService::class)
        ->synchronize($job->fresh(), $manager, 'automated_test', false);

    expect(\App\Modules\OrderFlow\Data\Models\FlowTask::withTrashed()->findOrFail($generated->id)->trashed())->toBeFalse();
});

test('phase readiness accepts legacy Completed status rows even when completed_at was missing', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $job = FlowJob::query()
        ->whereNotNull('current_phase_id')
        ->whereHas('currentPhase.taskPack')
        ->with(['currentPhase.taskPack.items'])
        ->firstOrFail();
    $completed = MasterRecord::where('workspace_id', $job->workspace_id)
        ->where('type', 'task_status')
        ->where('code', 'CMP')
        ->firstOrFail();

    app(\App\Modules\OrderFlow\Business\Services\TaskPackActivator::class)->activate($job, $job->currentPhase);

    $job->tasks()
        ->where('workflow_phase_id', $job->current_phase_id)
        ->whereIn('task_pack_item_id', $job->currentPhase->taskPack->items->where('is_required', true)->pluck('id'))
        ->update(['task_status_id' => $completed->id, 'completed_at' => null]);

    $result = app(\App\Modules\OrderFlow\Business\Services\PhaseReadinessEvaluator::class)
        ->evaluate($job->fresh(), $job->currentPhase);

    expect($result['task_gate']['met'])->toBeTrue();
});

test('a dynamically coded completed status works from every task transition endpoint', function () {
    $task = \App\Modules\OrderFlow\Data\Models\FlowTask::query()
        ->whereNull('dependency_id')
        ->whereNotNull('assignee_id')
        ->with('assignee')
        ->firstOrFail();
    $assignee = $task->assignee;

    $completed = MasterRecord::query()->create([
        'workspace_id' => $assignee->currentWorkspaceId(),
        'type' => 'task_status',
        'code' => 'TST-999',
        'name' => 'Completed',
        'description' => 'Dynamically configured completed status',
        'status' => 'active',
        'sort_order' => 999,
    ]);

    $this->actingAs($assignee)
        ->putJson(route('flowtrack.template-api.tasks.update', $task), [
            'task_status_id' => $completed->id,
            'assignee_id' => $task->assignee_id,
            'attention_required' => false,
            'completed_checklist_ids' => [],
            'comment' => 'Completed with a dynamically generated status code.',
        ])
        ->assertOk()
        ->assertJsonPath('task.statusId', $completed->id)
        ->assertJsonPath('task.progress', 100);

    expect($task->fresh()->completed_at)->not->toBeNull();
});


test('only the exact current-phase document requirement can be uploaded and linked', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $job = FlowJob::query()
        ->visibleTo($manager)
        ->whereNotNull('current_phase_id')
        ->with(['currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory'])
        ->firstOrFail();

    $requirements = app(\App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService::class)
        ->requirements($job);

    if ($requirements->isEmpty()) {
        $category = MasterRecord::where('workspace_id', $job->workspace_id)
            ->where('type', 'document_category')
            ->where('status', 'active')
            ->firstOrFail();
        $job->currentPhase->update(['document_category_id' => $category->id]);
        $job->unsetRelation('currentPhase');
        $job->load(['currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory']);
        $requirements = app(\App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService::class)
            ->requirements($job);
    }

    $requirement = $requirements->first();
    $otherCategory = MasterRecord::where('workspace_id', $job->workspace_id)
        ->where('type', 'document_category')
        ->where('id', '!=', $requirement['category_id'])
        ->firstOrFail();

    Storage::fake(config('filesystems.default'));

    $this->actingAs($manager)
        ->post(route('flowtrack.template-api.documents.store'), [
            'job_id' => $job->id,
            'workflow_phase_id' => $job->current_phase_id,
            'task_pack_item_id' => $requirement['task_pack_item_id'],
            'document_category_id' => $otherCategory->id,
            'file' => UploadedFile::fake()->create('wrong-category.pdf', 120, 'application/pdf'),
        ])
        ->assertUnprocessable();

    $response = $this->actingAs($manager)
        ->post(route('flowtrack.template-api.documents.store'), [
            'job_id' => $job->id,
            'workflow_phase_id' => $requirement['workflow_phase_id'],
            'task_pack_item_id' => $requirement['task_pack_item_id'],
            'document_category_id' => $requirement['category_id'],
            'file' => UploadedFile::fake()->create('job-required-document.pdf', 120, 'application/pdf'),
            'is_final' => true,
            'advance_phase' => false,
            'notes' => 'Uploaded from the Documents page.',
        ])
        ->assertOk()
        ->assertJsonPath('document.job', $job->code)
        ->assertJsonPath('document.categoryId', $requirement['category_id'])
        ->assertJsonPath('document.phaseId', $requirement['workflow_phase_id'])
        ->assertJsonPath('document.final', true);

    $this->assertDatabaseHas('flow_documents', [
        'id' => $response->json('document.dbId'),
        'flow_job_id' => $job->id,
        'workflow_phase_id' => $requirement['workflow_phase_id'],
        'task_pack_item_id' => $requirement['task_pack_item_id'],
        'document_category_id' => $requirement['category_id'],
        'is_final' => true,
    ]);
});

test('a Task Pack document requirement blocks task completion until the Job file is uploaded', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $job = FlowJob::query()
        ->whereNotNull('current_phase_id')
        ->whereHas('currentPhase.taskPack.items')
        ->with(['currentPhase.taskPack.items', 'tasks'])
        ->firstOrFail();
    $category = MasterRecord::where('workspace_id', $job->workspace_id)
        ->where('type', 'document_category')
        ->where('status', 'active')
        ->firstOrFail();
    $item = $job->currentPhase->taskPack->items->first();
    $item->update(['document_category_id' => $category->id]);
    $job->currentPhase->update(['auto_advance_on_ready' => false]);

    app(\App\Modules\OrderFlow\Business\Services\TaskPackActivator::class)
        ->activate($job, $job->currentPhase);
    $task = $job->tasks()->where('task_pack_item_id', $item->id)->firstOrFail();
    $completed = MasterRecord::where('workspace_id', $job->workspace_id)
        ->where('type', 'task_status')
        ->where('code', 'CMP')
        ->firstOrFail();

    expect(fn () => app(\App\Modules\OrderFlow\Application\Actions\UpdateTaskAction::class)->execute($task, [
        'task_status_id' => $completed->id,
        'attention_required' => false,
    ], $manager, 'automated_test'))->toThrow(\DomainException::class, 'Upload the required');

    Storage::fake(config('filesystems.default'));
    app(\App\Modules\OrderFlow\Application\Actions\UploadDocumentAction::class)->execute(
        $job->fresh(['currentPhase']),
        UploadedFile::fake()->create('required-task-file.pdf', 80, 'application/pdf'),
        $category->id,
        false,
        $manager,
        null,
        null,
        $job->current_phase_id,
        $item->id,
        false,
    );

    $updated = app(\App\Modules\OrderFlow\Application\Actions\UpdateTaskAction::class)->execute($task->fresh(), [
        'task_status_id' => $completed->id,
        'attention_required' => false,
    ], $manager, 'automated_test');

    expect($updated->completed_at)->not->toBeNull();
});


test('Task Pack activation always uses the pack mapped to the selected Workflow Phase', function () {
    $workflow = WorkflowTemplate::query()
        ->whereHas('phases', fn ($query) => $query->whereNotNull('task_pack_id'))
        ->with(['phases.taskPack.items'])
        ->firstOrFail();
    $phases = $workflow->phases->whereNotNull('task_pack_id')->values();
    $first = $phases->first();
    $target = $phases->first(fn ($phase) => (int) $phase->task_pack_id !== (int) $first->task_pack_id);
    expect($target)->not->toBeNull();

    $job = FlowJob::query()->where('workflow_template_id', $workflow->id)->firstOrFail();
    app(\App\Modules\OrderFlow\Business\Services\TaskPackActivator::class)->activate($job, $target);

    $generatedItemIds = $job->tasks()
        ->where('workflow_phase_id', $target->id)
        ->whereNotNull('task_pack_item_id')
        ->pluck('task_pack_item_id');

    expect($generatedItemIds)->not->toBeEmpty();
    expect($generatedItemIds->diff($target->taskPack->items->pluck('id')))->toBeEmpty();
    expect($generatedItemIds->intersect($first->taskPack->items->pluck('id')))->toBeEmpty();
});

test('a non-administrator sees and updates only Tasks assigned to them', function () {
    $user = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $authorization = app(\App\Modules\OrderFlow\Business\Services\FlowAuthorizationService::class);

    $assigned = \App\Modules\OrderFlow\Data\Models\FlowTask::query()
        ->whereHas('job', fn ($query) => $query->where('workspace_id', $user->currentWorkspaceId()))
        ->firstOrFail();
    $assigned->update(['assignee_id' => $user->id]);

    $other = \App\Modules\OrderFlow\Data\Models\FlowTask::query()
        ->where('id', '!=', $assigned->id)
        ->whereNotNull('assignee_id')
        ->where('assignee_id', '!=', $user->id)
        ->with('job')
        ->firstOrFail();

    $visibleIds = $authorization->applyTaskBoardVisibility(
        \App\Modules\OrderFlow\Data\Models\FlowTask::query(),
        $user,
    )->pluck('id');

    expect($authorization->isAdministrator($user))->toBeFalse()
        ->and($visibleIds)->toContain($assigned->id)
        ->and($visibleIds)->not->toContain($other->id)
        ->and($authorization->canUpdateTask($user, $assigned->fresh('job')))->toBeTrue()
        ->and($authorization->canUpdateTask($user, $other))->toBeFalse();
});
