<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\Role;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlowTrackDynamicBackendSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_creates_super_admin_and_manager(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'superadmin@flowtrack.cn',
            'is_super_admin' => true,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'manager@flowtrack.cn',
            'status' => 'active',
        ]);
    }

    public function test_authenticated_super_admin_can_load_dynamic_bootstrap(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();

        $response = $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/bootstrap');

        $response->assertOk()
            ->assertJsonStructure([
                'currentUser',
                'clients',
                'jobs',
                'tasks',
                'documents',
                'notifications',
                'workflows',
                'taskPacks',
                'masterData',
                'capabilities',
                'accessControl',
                'summaryMetrics',
            ]);

        $payload = $response->json();
        $this->assertEmpty($payload['jobs']);
        $this->assertEmpty($payload['tasks']);
        $this->assertNotEmpty($payload['workflows']);
        $this->assertNotEmpty($payload['taskPacks']);

        // Operational records intentionally load through bounded segments,
        // rather than restoring the previous full-database bootstrap payload.
        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/lists/jobs?per_page=20')
            ->assertOk()
            ->assertJsonPath('segment', 'jobs')
            ->assertJsonCount(18, 'data');

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/lists/tasks?per_page=20')
            ->assertOk()
            ->assertJsonPath('segment', 'tasks')
            ->assertJsonCount(20, 'data');
    }

    public function test_my_work_snapshot_is_strictly_read_only(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $jobCount = FlowJob::query()->withTrashed()->count();
        $taskCount = FlowTask::query()->withTrashed()->count();

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/my-work-snapshot')
            ->assertOk()
            ->assertJsonPath('source', 'database')
            ->assertJsonPath('readOnly', true);

        $this->assertSame($jobCount, FlowJob::query()->withTrashed()->count());
        $this->assertSame($taskCount, FlowTask::query()->withTrashed()->count());
    }

    public function test_browsing_job_details_does_not_restore_or_create_tasks(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $task = FlowTask::query()->whereNotNull('task_pack_item_id')->firstOrFail();
        $job = $task->job;
        $task->delete();
        $activeBefore = FlowTask::query()->where('flow_job_id', $job->id)->count();
        $allBefore = FlowTask::withTrashed()->where('flow_job_id', $job->id)->count();

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/jobs/'.urlencode((string) $job->code))
            ->assertOk()
            ->assertJsonPath('taskSet.readOnly', true)
            ->assertJsonPath('taskSet.createdOrRestored', 0);

        $this->assertSame($activeBefore, FlowTask::query()->where('flow_job_id', $job->id)->count());
        $this->assertSame($allBefore, FlowTask::withTrashed()->where('flow_job_id', $job->id)->count());
        $this->assertSoftDeleted('flow_tasks', ['id' => $task->id]);

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/jobs/'.$job->id.'/tasks')
            ->assertOk()
            ->assertJsonPath('taskSet.readOnly', true)
            ->assertJsonPath('taskSet.createdOrRestored', 0);

        $this->assertSame($activeBefore, FlowTask::query()->where('flow_job_id', $job->id)->count());
        $this->assertSame($allBefore, FlowTask::withTrashed()->where('flow_job_id', $job->id)->count());
        $this->assertSoftDeleted('flow_tasks', ['id' => $task->id]);
    }

    public function test_master_task_status_and_priority_colors_are_persisted_and_exposed(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $priority = MasterRecord::query()->where('type', MasterDataType::Priority->value)->where('code', 'MED')->firstOrFail();
        $status = MasterRecord::query()->where('type', MasterDataType::TaskStatus->value)->where('code', 'READY')->firstOrFail();

        $this->actingAs($superAdmin)->putJson('/flowtrack/template-api/master-data/'.$priority->id, [
            'type' => MasterDataType::Priority->value,
            'parent_id' => null,
            'name' => $priority->name,
            'description' => $priority->description,
            'status' => $priority->status,
            'sort_order' => $priority->sort_order,
            'color' => '#123456',
        ])->assertOk()->assertJsonPath('record.color', '#123456');

        $this->actingAs($superAdmin)->putJson('/flowtrack/template-api/master-data/'.$status->id, [
            'type' => MasterDataType::TaskStatus->value,
            'parent_id' => null,
            'name' => $status->name,
            'description' => $status->description,
            'status' => $status->status,
            'sort_order' => $status->sort_order,
            'color' => '#ABCDEF',
        ])->assertOk()->assertJsonPath('record.color', '#ABCDEF');

        $this->assertSame('#123456', MasterRecord::query()->findOrFail($priority->id)->metadata['color'] ?? null);
        $this->assertSame('#ABCDEF', MasterRecord::query()->findOrFail($status->id)->metadata['color'] ?? null);

        $priorityTask = FlowTask::query()->where('priority_id', $priority->id)->firstOrFail();
        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/tasks/'.urlencode((string) $priorityTask->code))
            ->assertOk()
            ->assertJsonPath('task.priorityColor', '#123456');

        $statusTask = FlowTask::query()->where('task_status_id', $status->id)->firstOrFail();
        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/tasks/'.urlencode((string) $statusTask->code))
            ->assertOk()
            ->assertJsonPath('task.statusColor', '#ABCDEF');
    }

    public function test_task_detail_api_exposes_final_template_fields(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $taskCode = FlowTask::query()->orderBy('id')->value('code');
        $this->assertNotNull($taskCode, 'The demo seed must contain a task for task-detail verification.');

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/tasks/'.urlencode((string) $taskCode))
            ->assertOk()
            ->assertJsonStructure([
                'task' => [
                    'dbId', 'id', 'job', 'jobDbId', 'jobContext', 'title', 'description',
                    'startDate', 'status', 'priority', 'phase', 'due',
                    'createdAt', 'createdBy', 'createdByInitials', 'updatedAt',
                    'assignee', 'assigneeId', 'attention', 'blockerReason',
                    'dependency', 'dependents', 'documentRequirement', 'documents',
                    'checklist', 'comments', 'history', 'mentionableUsers',
                    'canUpdate', 'canAssign', 'canDelete', 'canManageChecklist', 'canChangeDueDate',
                ],
                'job' => ['dbId', 'id', 'title', 'client', 'clientName', 'health', 'delivery', 'progress'],
            ]);
    }

    public function test_task_without_task_pack_document_requirement_has_no_document_area(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $task = FlowTask::query()
            ->whereNull('document_category_id')
            ->whereHas('packItem', fn ($query) => $query->whereNull('document_category_id'))
            ->where('title', 'like', 'Upload%')
            ->firstOrFail();

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/tasks/'.urlencode((string) $task->code))
            ->assertOk()
            ->assertJsonPath('task.isDocumentTask', false)
            ->assertJsonPath('task.documentRequirement', null)
            ->assertJsonPath('task.canUploadDocument', false);
    }

    public function test_task_pack_required_document_is_visible_and_blocks_every_status_change_until_evidence_exists(): void
    {
        Storage::fake('flowtrack_documents');
        config(['flowtrack.documents_disk' => 'flowtrack_documents']);
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $job = FlowJob::query()->whereNotNull('current_phase_id')->firstOrFail();
        $task = FlowTask::query()
            ->with(['packItem', 'job'])
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $job->current_phase_id)
            ->whereNull('completed_at')
            ->whereNotNull('task_pack_item_id')
            ->firstOrFail();
        $category = MasterRecord::query()
            ->where('workspace_id', $task->job->workspace_id)
            ->where('type', MasterDataType::DocumentCategory->value)
            ->where('status', 'active')
            ->firstOrFail();
        $targetStatus = MasterRecord::query()
            ->where('workspace_id', $task->job->workspace_id)
            ->where('type', MasterDataType::TaskStatus->value)
            ->where('status', 'active')
            ->whereKeyNot($task->task_status_id)
            ->whereNotIn('code', ['CMP', 'COMPLETED'])
            ->firstOrFail();

        $task->packItem->update(['document_category_id' => $category->id]);
        $task->update([
            'document_category_id' => $category->id,
            'document_requirement_source' => 'task_pack_item',
        ]);

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/jobs/'.urlencode((string) $job->code))
            ->assertOk()
            ->assertJsonFragment([
                'task_pack_item_id' => (int) $task->task_pack_item_id,
                'category_id' => (int) $category->id,
                'category_name' => $category->name,
                'flow_task_id' => (int) $task->id,
                'present' => false,
            ]);

        $this->actingAs($superAdmin)
            ->getJson('/flowtrack/template-api/tasks/'.urlencode((string) $task->code))
            ->assertOk()
            ->assertJsonPath('task.documentRequirement.categoryId', (int) $category->id)
            ->assertJsonPath('task.documentRequirementSatisfied', false)
            ->assertJsonPath('task.statusChangeBlockedByDocument', true);

        // The document prerequisite applies to every real status transition,
        // not only to the Completed status.
        $this->actingAs($superAdmin)
            ->putJson('/flowtrack/template-api/tasks/'.$task->id, [
                'task_status_id' => $targetStatus->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertSame((int) $task->task_status_id, (int) $task->fresh()->task_status_id);

        $this->actingAs($superAdmin)
            ->post('/flowtrack/template-api/documents', [
                'job_id' => $job->id,
                'workflow_phase_id' => $task->workflow_phase_id,
                'task_pack_item_id' => $task->task_pack_item_id,
                'task_id' => $task->id,
                'document_category_id' => $category->id,
                'file' => UploadedFile::fake()->create('required-evidence.pdf', 64, 'application/pdf'),
            ])
            ->assertOk();

        $task->refresh();
        $this->actingAs($superAdmin)
            ->putJson('/flowtrack/template-api/tasks/'.$task->id, [
                'task_status_id' => $targetStatus->id,
            ])
            ->assertOk()
            ->assertJsonPath('task.statusId', (int) $targetStatus->id)
            ->assertJsonPath('task.documentRequirementSatisfied', true)
            ->assertJsonPath('task.statusChangeBlockedByDocument', false);
    }

    public function test_super_admin_can_add_and_delete_task_checklist_items(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $task = FlowTask::query()
            ->with(['status', 'job'])
            ->whereNull('completed_at')
            ->firstOrFail();

        $basePayload = [
            'title' => $task->title,
            'description' => $task->description,
            'task_status_id' => $task->task_status_id,
            'assignee_id' => $task->assignee_id,
            'priority_id' => $task->priority_id,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'attention_required' => (bool) $task->attention_required,
            'blocker_reason' => $task->blocker_reason,
        ];

        $this->actingAs($superAdmin)
            ->putJson('/flowtrack/template-api/tasks/'.$task->id, [
                ...$basePayload,
                'checklist_add' => 'Admin checklist smoke item',
            ])
            ->assertOk()
            ->assertJsonPath('task.canManageChecklist', true);

        $itemId = $task->checklistItems()
            ->where('label', 'Admin checklist smoke item')
            ->value('id');
        $this->assertNotNull($itemId);

        $this->actingAs($superAdmin)
            ->putJson('/flowtrack/template-api/tasks/'.$task->id, [
                ...$basePayload,
                'checklist_delete_id' => $itemId,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('flow_task_checklist_items', ['id' => $itemId]);
        $this->assertDatabaseHas('flow_activities', [
            'subject_type' => FlowTask::class,
            'subject_id' => $task->id,
            'event' => 'task.checklist_changed',
        ]);
    }

    public function test_task_attention_can_be_marked_and_cleared_from_task_details(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $task = FlowTask::query()
            ->with(['status', 'job'])
            ->whereNull('completed_at')
            ->whereHas('status', fn ($query) => $query->whereNotIn('code', ['BLK', 'ATTN', 'HOLD']))
            ->firstOrFail();

        $payload = [
            'title' => $task->title,
            'description' => $task->description,
            'task_status_id' => $task->task_status_id,
            'assignee_id' => $task->assignee_id,
            'priority_id' => $task->priority_id,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'blocker_reason' => null,
        ];

        $this->actingAs($superAdmin)
            ->putJson('/flowtrack/template-api/tasks/'.$task->id, [
                ...$payload,
                'attention_required' => true,
            ])
            ->assertOk()
            ->assertJsonPath('task.attention', true);

        $this->assertDatabaseHas('flow_tasks', [
            'id' => $task->id,
            'attention_required' => true,
        ]);

        $this->actingAs($superAdmin)
            ->putJson('/flowtrack/template-api/tasks/'.$task->id, [
                ...$payload,
                'attention_required' => false,
            ])
            ->assertOk()
            ->assertJsonPath('task.attention', false);

        $this->assertDatabaseHas('flow_tasks', [
            'id' => $task->id,
            'attention_required' => false,
        ]);
    }


    public function test_super_admin_can_upload_workspace_logo_and_regular_user_cannot(): void
    {
        Storage::fake('public');
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $regularUser = User::query()->where('email', 'zhang.min@flowtrack.cn')->firstOrFail();

        $response = $this->actingAs($superAdmin)
            ->post('/flowtrack/template-api/branding/logo', [
                'logo' => UploadedFile::fake()->image('brand.png', 256, 256),
            ]);

        $response->assertOk()
            ->assertJsonPath('branding.hasCustomLogo', true);

        $logoPath = $superAdmin->currentWorkspaceMembership()?->workspace?->fresh()?->logo_path;
        $this->assertNotNull($logoPath);
        Storage::disk('public')->assertExists($logoPath);

        // actingAs() changes the guard user but deliberately keeps the current
        // test session. Reset it so single-active-session middleware can adopt
        // a valid token for the second actor instead of redirecting as replaced.
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        $this->actingAs($regularUser)
            ->post('/flowtrack/template-api/branding/logo', [
                'logo' => UploadedFile::fake()->image('blocked.png', 128, 128),
            ])
            ->assertForbidden();
    }

    public function test_uploaded_workspace_logo_is_rendered_on_login_page(): void
    {
        Storage::fake('public');
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $this->actingAs($superAdmin)
            ->post('/flowtrack/template-api/branding/logo', [
                'logo' => UploadedFile::fake()->image('brand.png', 256, 256),
            ])
            ->assertOk();

        $this->app['auth']->guard()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('data-flowtrack-brand-mark', false)
            ->assertSee('storage/branding/', false);
    }

    public function test_document_uploads_are_private_and_receive_unique_codes(): void
    {
        Storage::fake('flowtrack_documents');
        config(['flowtrack.documents_disk' => 'flowtrack_documents']);
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $job = FlowJob::query()->firstOrFail();

        foreach (['brief-one.pdf', 'brief-two.pdf'] as $filename) {
            $this->actingAs($superAdmin)
                ->post("/flowtrack/template-api/jobs/{$job->id}/attachments", [
                    'file' => UploadedFile::fake()->create($filename, 128, 'application/pdf'),
                ])
                ->assertCreated();
        }

        $documents = FlowDocument::query()->latest('id')->take(2)->get();
        $this->assertCount(2, $documents);
        $this->assertCount(2, $documents->pluck('code')->unique());
        foreach ($documents as $document) {
            $this->assertSame('flowtrack_documents', $document->currentVersion->disk);
            Storage::disk('flowtrack_documents')->assertExists($document->currentVersion->path);
        }
    }

    public function test_created_user_password_is_hashed_and_never_returned(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $superAdmin = User::query()->where('email', 'superadmin@flowtrack.cn')->firstOrFail();
        $role = Role::query()->where('workspace_id', $superAdmin->currentWorkspaceId())
            ->where('code', 'MEMBER')
            ->firstOrFail();
        $plainPassword = 'SecureUser123!';

        $response = $this->actingAs($superAdmin)->postJson('/flowtrack/template-api/access/members', [
            'name' => 'Secure Test User',
            'email' => 'secure.user@example.com',
            'password' => $plainPassword,
            'password_confirmation' => $plainPassword,
            'role_id' => $role->id,
            'status' => 'active',
            'locale' => 'en',
        ])->assertCreated();

        $this->assertStringNotContainsString($plainPassword, $response->getContent());
        $created = User::query()->where('email', 'secure.user@example.com')->firstOrFail();
        $this->assertNotSame($plainPassword, $created->getRawOriginal('password'));
        $this->assertTrue(Hash::check($plainPassword, $created->getRawOriginal('password')));
    }

    public function test_authenticated_manager_can_open_main_blade_pages(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $manager = User::query()->where('email', 'manager@flowtrack.cn')->firstOrFail();

        foreach ([
            '/dashboard',
            '/flowtrack/view/jobs',
            '/flowtrack/view/board',
            '/flowtrack/view/mywork',
            '/flowtrack/view/clients',
            '/flowtrack/view/documents',
            '/flowtrack/view/workflowsetup',
            '/flowtrack/view/masterdata',
            '/flowtrack/view/setup',
        ] as $uri) {
            $this->actingAs($manager)->get($uri)->assertOk()->assertSee('FlowTrack');
        }
    }
}
