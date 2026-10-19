<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\InquiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquirySharedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_with_the_same_inquiry_view_access_see_the_same_complete_inquiry_list(): void
    {
        // Use own_records deliberately: Inquiry View is workspace-wide and must not
        // become participant-only just because this generic matrix scope is selected.
        $role = $this->inquiryRole('own_records', ['view']);
        $firstUser = User::factory()->create([
            'role_id' => $role->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $secondUser = User::factory()->create([
            'role_id' => $role->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $other = User::factory()->create(['is_active' => true]);
        $client = Client::create([
            'name' => 'Inquiry Shared Visibility Client',
            'code' => 'ISVC-'.uniqid(),
            'is_active' => true,
        ]);

        $createdByFirst = $this->inquiry($client, $firstUser, $other, 'FIRST-CREATED');
        $assignedToFirst = $this->inquiry($client, $other, $other, 'FIRST-ASSIGNED');
        InquiryTask::create([
            'inquiry_id' => $assignedToFirst->id,
            'assignee_id' => $firstUser->id,
            'title' => 'Assigned to first user',
            'sequence' => 1,
            'status' => 'Ready',
        ]);
        $createdBySecond = $this->inquiry($client, $secondUser, $other, 'SECOND-CREATED');
        $unrelated = $this->inquiry($client, $other, $other, 'UNRELATED');

        $expectedIds = [
            $createdByFirst->id,
            $assignedToFirst->id,
            $createdBySecond->id,
            $unrelated->id,
        ];

        foreach ([$firstUser, $secondUser] as $actor) {
            $visibleIds = app(InquiryService::class)
                ->listQuery($actor, [])
                ->whereIn('inquiries.id', $expectedIds)
                ->pluck('inquiries.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertEqualsCanonicalizing($expectedIds, $visibleIds);
        }

        // Summary cards use the same shared Inquiry visibility boundary as the list.
        $this->assertSame(4, app(InquiryService::class)->metrics($firstUser)['createdToday']);
        $this->assertSame(4, app(InquiryService::class)->metrics($secondUser)['createdToday']);
    }

    public function test_inquiry_view_access_does_not_grant_write_actions(): void
    {
        $role = $this->inquiryRole('own_records', ['view']);
        $viewer = User::factory()->create([
            'role_id' => $role->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $other = User::factory()->create(['is_active' => true]);
        $client = Client::create([
            'name' => 'Inquiry Permission Client',
            'code' => 'IPCV-'.uniqid(),
            'is_active' => true,
        ]);
        $inquiry = $this->inquiry($client, $other, $other, 'VIEW-ONLY');

        $service = app(InquiryService::class);

        $this->assertTrue($service->visibleQuery($viewer)->whereKey($inquiry->id)->exists());
        $this->assertFalse($service->canEdit($viewer, $inquiry));
        $this->assertFalse($viewer->canModule('inquiries', 'delete'));
        $this->assertFalse($viewer->canModule('inquiries', 'assign'));
    }

    public function test_user_without_inquiry_view_access_cannot_see_inquiries(): void
    {
        $role = $this->inquiryRole('own_records', ['create']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $other = User::factory()->create(['is_active' => true]);
        $client = Client::create([
            'name' => 'Inquiry No View Client',
            'code' => 'INVC-'.uniqid(),
            'is_active' => true,
        ]);
        $inquiry = $this->inquiry($client, $other, $other, 'NO-VIEW');

        $this->assertFalse(
            app(InquiryService::class)->visibleQuery($user)->whereKey($inquiry->id)->exists()
        );
    }

    public function test_admin_and_super_admin_keep_full_inquiry_visibility(): void
    {
        $client = Client::create([
            'name' => 'Inquiry Admin Client',
            'code' => 'IAC-'.uniqid(),
            'is_active' => true,
        ]);
        $creator = User::factory()->create(['is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);

        $first = $this->inquiry($client, $creator, $owner, 'ADMIN-A');
        $second = $this->inquiry($client, $creator, $owner, 'ADMIN-B');

        $adminRole = Role::query()->firstOrCreate(
            ['slug' => 'admin'],
            [
                'workspace_id' => 1,
                'name' => 'Admin',
                'code' => 'ADMIN',
                'description' => 'Administrator',
                'default_scope' => 'all_records',
                'is_system' => true,
                'is_active' => true,
                'sensitive_fields' => [],
            ],
        );
        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $superAdmin = User::factory()->create([
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        foreach ([$admin, $superAdmin] as $actor) {
            $visibleIds = app(InquiryService::class)
                ->listQuery($actor, [])
                ->whereIn('inquiries.id', [$first->id, $second->id])
                ->pluck('inquiries.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertEqualsCanonicalizing([$first->id, $second->id], $visibleIds);
        }
    }

    private function inquiryRole(string $scope, array $actions): Role
    {
        $role = Role::create([
            'workspace_id' => 1,
            'name' => 'Inquiry Access '.uniqid(),
            'slug' => 'inquiry-access-'.uniqid(),
            'code' => 'INQ_ACCESS_'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'description' => 'Inquiry shared visibility test role',
            'default_scope' => $scope,
            'is_system' => false,
            'is_active' => true,
            'sensitive_fields' => [],
        ]);

        RoleModuleAccess::create([
            'role_id' => $role->id,
            'module_code' => 'inquiries',
            'record_scope' => $scope,
            'actions' => $actions,
        ]);

        return $role;
    }

    private function inquiry(Client $client, User $creator, User $owner, string $suffix): Inquiry
    {
        return Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-SHARED-'.$suffix.'-'.uniqid(),
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'created_by' => $creator->id,
            'received_date' => today(),
            'subject' => 'Shared Inquiry visibility '.$suffix,
            'status' => 'In Progress',
        ]);
    }
}
