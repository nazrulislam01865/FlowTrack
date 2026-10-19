<?php

namespace Tests\Feature;

use App\Actions\Clients\PermanentlyDeleteClientAction;
use App\Actions\MasterData\DeleteMasterRecordAction;
use App\Actions\MasterData\DeleteProductCategoriesAction;
use App\Actions\Setup\DeleteTaskPackAction;
use App\Models\Client;
use App\Models\ClientShippingAddress;
use App\Models\Document;
use App\Models\MasterRecord;
use App\Models\TaskPack;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Services\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase7AdministrationDeletionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_data_parent_cannot_be_deleted_while_child_records_exist(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);
        $workspaceId = app(MasterDataService::class)->workspaceId();

        $country = MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'type' => 'country',
            'code' => 'P7-COUNTRY',
            'name' => 'Phase 7 Country',
            'status' => 'active',
        ]);
        MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'type' => 'state',
            'code' => 'P7-STATE',
            'name' => 'Phase 7 State',
            'parent_id' => $country->id,
            'status' => 'active',
        ]);

        try {
            app(DeleteMasterRecordAction::class)->execute($country->id);
            $this->fail('Deleting a Master Data parent with children must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('record', $exception->errors());
        }

        $this->assertDatabaseHas('master_records', ['id' => $country->id, 'deleted_at' => null]);
    }

    public function test_product_category_delete_action_unassigns_product_instead_of_deleting_it(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);
        $workspaceId = app(MasterDataService::class)->workspaceId();

        $category = MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'type' => 'product_category',
            'code' => 'P7-CAT',
            'name' => 'Phase 7 Category',
            'status' => 'active',
        ]);
        $product = MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'type' => 'product',
            'code' => 'P7-PROD',
            'name' => 'Phase 7 Product',
            'parent_id' => $category->id,
            'metadata' => ['category' => $category->name],
            'status' => 'active',
        ]);

        $result = app(DeleteProductCategoriesAction::class)->execute(['product:'.$category->id]);

        $this->assertSame(1, $result['product_categories']);
        $this->assertSame(1, $result['products']);
        $this->assertDatabaseMissing('master_records', ['id' => $category->id]);
        $this->assertDatabaseHas('master_records', ['id' => $product->id, 'type' => 'product']);

        $product->refresh();
        $this->assertNull($product->parent_id);
        $this->assertTrue((bool) data_get($product->metadata, 'taxonomy_unassigned'));
    }

    public function test_task_pack_delete_action_unassigns_setup_phase_without_deleting_the_phase(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);

        $legacy = Workflow::query()->create([
            'name' => 'Phase 7 Workflow',
            'slug' => 'phase-7-workflow-'.uniqid(),
            'is_active' => true,
        ]);
        $template = WorkflowTemplate::query()->create([
            'id' => $legacy->id,
            'workspace_id' => 1,
            'code' => 'P7-WF',
            'name' => 'Phase 7 Workflow',
            'applies_to' => 'inquiries',
            'is_active' => true,
            'is_default' => false,
            'version' => 1,
        ]);
        $pack = TaskPack::query()->create([
            'workspace_id' => 1,
            'code' => 'P7-PACK',
            'name' => 'Phase 7 Pack',
            'slug' => 'phase-7-pack-'.uniqid(),
            'is_active' => true,
        ]);
        $phase = WorkflowPhase::query()->create([
            'workflow_id' => $legacy->id,
            'workflow_template_id' => $template->id,
            'task_pack_id' => $pack->id,
            'sequence' => 1,
            'name' => 'Phase 7 Stage',
            'short_name' => 'P7',
            'allow_job_start' => true,
            'can_skip' => false,
            'is_skippable' => false,
            'requires_approval' => false,
            'auto_advance_on_ready' => false,
            'is_active' => true,
        ]);

        app(DeleteTaskPackAction::class)->execute($pack->id);

        $this->assertDatabaseMissing('task_packs', ['id' => $pack->id]);
        $this->assertDatabaseHas('workflow_phases', ['id' => $phase->id, 'task_pack_id' => null]);
    }

    public function test_client_permanent_delete_action_removes_profile_children_but_preserves_history(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);
        $client = Client::query()->create([
            'code' => 'P7-CLIENT',
            'name' => 'Phase 7 Deleted Client',
            'created_by' => $user->id,
            'is_active' => false,
            'archived_at' => now()->subDay(),
            'archived_by' => $user->id,
        ]);
        ClientShippingAddress::query()->create([
            'client_id' => $client->id,
            'label' => 'Main',
            'address_line1' => '123 Phase Seven Road',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'zip' => '1200',
            'country' => 'Bangladesh',
            'is_default' => true,
            'sort_order' => 0,
        ]);
        $document = Document::query()->create([
            'document_number' => 'DOC-P7-001',
            'client_id' => $client->id,
            'name' => 'Preserved history.pdf',
            'path' => 'documents/p7-history.pdf',
            'size' => 10,
        ]);

        app(PermanentlyDeleteClientAction::class)->execute($user, $client->id);

        $client->refresh();
        $this->assertNotNull($client->purged_at);
        $this->assertSame('Deleted client #'.$client->id, $client->name);
        $this->assertDatabaseMissing('client_shipping_addresses', ['client_id' => $client->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'client_id' => $client->id]);
    }
}
