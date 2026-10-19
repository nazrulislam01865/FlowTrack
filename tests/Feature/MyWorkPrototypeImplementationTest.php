<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyWorkPrototypeImplementationTest extends TestCase
{
    public function test_my_work_keeps_stage_cards_and_restores_the_previous_grouped_table_and_filter_bar(): void
    {
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringContainsString('<h1>My Tasks</h1>', $view);
        $this->assertStringContainsString('class="toolbar ft-list-filter-bar"', $view);
        $this->assertStringContainsString('class="phase-filters"', $view);
        $this->assertStringContainsString('class="completed-toggle', $view);
        $this->assertStringContainsString('Sort: Action priority', $view);
        $this->assertStringContainsString('class="task-table-scroll"', $view);
        $this->assertStringContainsString('class="task-head"', $view);
        $this->assertStringContainsString('class="order-group"', $view);
        $this->assertStringContainsString('class="order-head"', $view);
        $this->assertStringContainsString('class="task-row"', $view);
        $this->assertStringContainsString('Task</span><span>Phase</span><span>Assignee</span><span>Due</span><span>Status</span><span>Flag</span><span>Updated</span><span>View', $view);

        $this->assertStringContainsString('<x-orders.workflow-stage-overview', $view);
        $this->assertStringContainsString('My tasks by workflow stage', $view);
        $this->assertStringNotContainsString('ft-summary-card-grid', $view);
        $this->assertStringNotContainsString('orders-modern-table ft-my-task-table', $view);
    }

    public function test_my_work_keeps_the_shared_application_sidebar_instead_of_overriding_it(): void
    {
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringNotContainsString('width:198px', $view);
        $this->assertStringNotContainsString('flex:0 0 198px', $view);
        $this->assertStringNotContainsString('.ft-sidebar-logout-form{display:none}', $view);
        $this->assertStringNotContainsString('.sidebar{width:66px', $view);
        $this->assertStringNotContainsString('.nav-btn{font-size:13px}', $view);
    }

    public function test_my_work_groups_and_pages_jobs_before_loading_task_rows(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));

        $this->assertStringContainsString("->groupBy('tasks.flow_job_id')", $service);
        $this->assertStringContainsString("->fromSub(\$grouped, 'my_work_groups')", $service);
        $this->assertStringContainsString('->paginate(max(1, min(self::JOBS_PER_PAGE, $perPage))', $service);
        $this->assertStringContainsString("->whereIn('tasks.flow_job_id', \$jobIds)", $service);
        $activeScope = strstr($service, 'public function activeVisibleTaskQuery(User $user): Builder');
        $activeScope = strstr($activeScope, 'private function applyStructuralActiveTaskConstraint', true);
        $this->assertStringContainsString("->where('tasks.assignee_id', \$user->id)", $activeScope);
        $this->assertStringNotContainsString('isAdministrator($user)', $activeScope);
        $this->assertStringContainsString('applyStructuralActiveTaskConstraint($query)', $activeScope);
        $this->assertStringContainsString("->whereNull('completed_at')", $service);
        $this->assertStringContainsString("->whereNotIn('status', JobService::INACTIVE_STATUSES)", $service);
        $this->assertStringContainsString("->where('my_work_mention_activity.event', 'task.comment')", $service);
        $this->assertStringContainsString('mention_user_ids', $service);
    }

    public function test_inline_status_update_is_renderless_and_refreshes_personal_active_workflow_rows(): void
    {
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertMatchesRegularExpression('/#\[Renderless\]\s+public function updateTaskStatus\b/', $component);
        $this->assertStringContainsString('lockForUpdate()', $component);
        $this->assertStringContainsString('This task changed since the list was loaded.', $component);
        $this->assertStringContainsString('select.disabled=true', $view);
        $this->assertStringContainsString('select.value=previous', $view);
        $this->assertStringContainsString("\$result['refresh'] = true;", $component);
        $this->assertStringContainsString('if(result.refresh || (result.completed && @js($hideCompleted)))await $wire.$refresh();', $view);
    }

    public function test_my_work_restored_table_keeps_inline_assignee_and_due_date_editing(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringContainsString('<span>Assignee</span>', $view);
        $this->assertStringContainsString("'assignee' => (string) (\$task->getAttribute('my_work_assignee_name') ?: 'Unassigned')", $service);
        $this->assertStringContainsString("->leftJoin('users as my_work_assignees'", $service);
        $this->assertStringContainsString("'dueValue' => \$dueDate ?: ''", $service);
        $this->assertStringContainsString("'dueDisplay' => \$task->due_date?->format('M j, Y') ?? 'Set due date'", $service);
        $this->assertStringContainsString("window.FlowTrack.ui.inlineEdit({ key: @js('my-work-task-", $view);
        $this->assertStringContainsString('x-ref="myWorkDue"', $view);
        $this->assertStringContainsString('$wire.updateTaskDueDate(', $view);
        $this->assertMatchesRegularExpression('/#\[Renderless\]\s+public function updateTaskDueDate\b/', $component);
        $this->assertStringContainsString("validator(['date' => \$date], ['date' => ['date']])->validate();", $component);
    }
}
