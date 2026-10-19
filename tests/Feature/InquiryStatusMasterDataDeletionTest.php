<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\MasterValue;
use App\Models\User;
use App\Services\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryStatusMasterDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inquiry_task_status_can_be_deleted_without_rewriting_historical_inquiry_text(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();

        $status = MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'type' => 'inquiry_task_status',
            'code' => 'IST-999',
            'name' => 'Custom Review',
            'status' => 'active',
            'sort_order' => 999,
        ]);

        MasterValue::query()->create([
            'group_key' => 'inquiry_task_statuses',
            'code' => 'IST-999',
            'name' => 'Custom Review',
            'is_active' => true,
        ]);

        $client = Client::query()->create([
            'name' => 'Inquiry Status Client',
            'code' => 'ISC-999',
            'is_active' => true,
        ]);

        $inquiry = Inquiry::query()->create([
            'workspace_id' => $workspaceId,
            'inquiry_number' => 'INQ-STATUS-DELETE-001',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'received_date' => now()->toDateString(),
            'subject' => 'Status deletion regression',
            'status' => 'Custom Review',
        ]);

        // Warm the active-status cache so deletion also proves cache invalidation.
        $this->assertTrue($service->active('inquiry_task_status')->contains('id', $status->id));

        $service->delete($status->id);

        $this->assertSoftDeleted('master_records', ['id' => $status->id]);
        $this->assertDatabaseMissing('master_values', [
            'group_key' => 'inquiry_task_statuses',
            'code' => 'IST-999',
        ]);
        $this->assertDatabaseHas('inquiries', [
            'id' => $inquiry->id,
            'status' => 'Custom Review',
            'deleted_at' => null,
        ]);
        $this->assertFalse($service->active('inquiry_task_status')->contains('id', $status->id));
    }

    public function test_soft_deleted_inquiries_do_not_prevent_inquiry_task_status_deletion(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);

        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();

        $status = MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'type' => 'inquiry_task_status',
            'code' => 'IST-998',
            'name' => 'Old Status',
            'status' => 'active',
            'sort_order' => 998,
        ]);

        $client = Client::query()->create([
            'name' => 'Deleted Inquiry Client',
            'code' => 'ISC-998',
            'is_active' => true,
        ]);

        $inquiry = Inquiry::query()->create([
            'workspace_id' => $workspaceId,
            'inquiry_number' => 'INQ-STATUS-DELETE-002',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'received_date' => now()->toDateString(),
            'subject' => 'Soft-delete regression',
            'status' => 'Old Status',
        ]);
        $inquiry->delete();

        $service->delete($status->id);

        $this->assertSoftDeleted('master_records', ['id' => $status->id]);
    }
}
