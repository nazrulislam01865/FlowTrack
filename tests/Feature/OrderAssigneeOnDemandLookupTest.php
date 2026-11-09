<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Regression: task/stage/owner pickers fetch users only after explicit opening. */
class OrderAssigneeOnDemandLookupTest extends TestCase
{
    public function test_task_stage_and_owner_picker_do_not_embed_or_prefetch_users(): void
    {
        $task = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $stage = file_get_contents(resource_path('views/components/jobs/order-detail/stage-card.blade.php'));
        $header = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));
        $picker = file_get_contents(resource_path('views/components/ui/inline-remote-user.blade.php'));
        $javascript = file_get_contents(resource_path('../resources/js/components/list-filters.js'));

        foreach ([$task, $stage, $header, $planning] as $view) {
            $this->assertStringContainsString('<x-ui.inline-remote-user', $view);
            $this->assertStringNotContainsString('User::where(\'is_active\'', $view);
        }
        $this->assertStringContainsString('initialItems: []', $picker);
        $this->assertStringContainsString("endpoint: @js(route('filter-options.index', ['type' => 'users']))", $picker);
        $this->assertStringContainsString('x-on:ft-inline-remote-open.stop=', $picker);
        $this->assertStringContainsString('this.searchOptions(true);', $javascript);
        $this->assertStringContainsString('openMenu() {', $javascript);
        $this->assertStringContainsString('x-on:click.stop="openRemotePicker', $task);
        $this->assertStringContainsString('x-on:click.stop="openRemotePicker', $stage);
        $taskAction = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTasks.php'));
        $this->assertStringContainsString('whereKey($assigneeId)->where(\'is_active\', true)->exists()', $taskAction);
        $this->assertStringNotContainsString('$this->userOptions($user)->pluck(\'id\')', $taskAction);
    }

    public function test_mention_directories_are_not_removed(): void
    {
        $workflow = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $parent = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $this->assertStringContainsString('MentionService::class)->optionsForJob($job, $user)', $workflow);
        $this->assertStringContainsString('MentionService::class)->optionsForJob($selected, $user)', $parent);
    }
}
