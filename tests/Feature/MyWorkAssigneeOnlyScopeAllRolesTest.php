<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyWorkAssigneeOnlyScopeAllRolesTest extends TestCase
{
    public function test_order_my_tasks_scope_is_unconditionally_limited_to_the_authenticated_assignee(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));

        $activeScope = strstr($service, 'public function activeVisibleTaskQuery(User $user): Builder');
        $activeScope = strstr($activeScope, 'private function applyStructuralActiveTaskConstraint', true);

        $this->assertStringContainsString("->where('tasks.assignee_id', \$user->id)", $activeScope);
        $this->assertStringNotContainsString('isAdministrator($user)', $activeScope);
        $this->assertStringNotContainsString("->orWhereHas('job', fn (Builder \$job) => \$job->where('created_by', \$user->id))", $activeScope);
        $this->assertStringNotContainsString('visibleByConfiguredAccess', $activeScope);
    }

    public function test_inquiry_my_tasks_groups_metrics_and_badge_use_the_same_personal_assignee_scope(): void
    {
        $service = file_get_contents(app_path('Services/LegacyInquiryService.php'));

        $this->assertStringContainsString('private function assignedInquiryTaskQueryForMyWork(User $user): Builder', $service);
        $this->assertStringContainsString("->where('inquiry_tasks.assignee_id', \$user->id)", $service);
        $this->assertSame(3, substr_count($service, '$this->assignedInquiryTaskQueryForMyWork($user)'));
    }

    public function test_admin_and_super_admin_reassignment_refreshes_the_personal_queue_too(): void
    {
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));

        $this->assertStringNotContainsString('public bool $administratorView', $component);
        $this->assertStringNotContainsString('!$this->administratorView && !$needsListRefresh', $component);
        $this->assertStringContainsString('findPersonalVisibleTask(auth()->user(), (int) $updatedTask->id)', $component);
        $this->assertStringContainsString('$result[\'refresh\'] = $needsListRefresh;', $component);
    }
}
