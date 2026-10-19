<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderTaskAssignmentPerformanceStage7Test extends TestCase
{
    public function test_order_inline_assignment_uses_the_narrow_assignment_service_path(): void
    {
        $livewire = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTasks.php'));
        $start = strpos($livewire, 'public function updateTaskAssigneeFromJob');
        $end = strpos($livewire, 'public function updateTaskDueDateFromJob', $start);
        $method = substr($livewire, $start, $end - $start);

        $this->assertStringContainsString("'assignee:id,name,profile_image_path'", $method);
        $this->assertStringContainsString("'job.members'", $method);
        $this->assertStringContainsString('->updateAssignee($task, $assignee, $actor)', $method);
        $this->assertStringNotContainsString("updateDetailField(\$task, 'assignee_id'", $method);
    }

    public function test_manual_assignment_skips_status_and_progress_recalculation_but_preserves_audit_and_summary(): void
    {
        $tasks = file_get_contents(app_path('Services/TaskService.php'));
        $start = strpos($tasks, 'public function updateAssignee');
        $end = strpos($tasks, 'public function updateDetailField', $start);
        $method = substr($tasks, $start, $end - $start);

        $this->assertStringContainsString("'task.field_updated'", $method);
        $this->assertStringContainsString("'field' => 'assignee_id'", $method);
        $this->assertStringContainsString('FlowJobMember::firstOrCreate', $method);
        $this->assertStringContainsString('updateNextTaskAssignee($task)', $method);
        $this->assertStringContainsString('invalidateNotificationCaches: false', $method);
        $this->assertStringNotContainsString('refreshJobState(', $method);
        $this->assertStringNotContainsString('OrderTaskFlagService::class', $method);
        $this->assertStringNotContainsString('recalculateProgress(', $method);
        $this->assertStringNotContainsString('maybeAutoAdvance(', $method);
    }

    public function test_assignment_notification_fanout_reuses_parent_and_avoids_redundant_versioned_cache_deletes(): void
    {
        $notifications = file_get_contents(app_path('Services/NotificationService.php'));
        $access = file_get_contents(app_path('Services/AccessControlService.php'));

        $this->assertStringContainsString('bool $invalidateRecipientCaches = true', $notifications);
        $this->assertStringContainsString('if ($invalidateRecipientCaches)', $notifications);
        $this->assertStringContainsString("->with('roles')", $notifications);
        $this->assertStringContainsString("\$visibleTask->setRelation('job', \$visibleJob)", $notifications);
        $this->assertStringContainsString("\$task->relationLoaded('job')", $notifications);
        $this->assertStringContainsString("\$task->relationLoaded('job')", $access);
    }

    public function test_assignee_sync_payload_does_not_refresh_an_already_saved_task_again(): void
    {
        $livewire = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTasks.php'));
        $start = strpos($livewire, 'private function dispatchTaskAssigneeSync');
        $end = strpos($livewire, 'private function dispatchOrderRuntimeRefresh', $start);
        $method = substr($livewire, $start, $end - $start);

        $this->assertStringContainsString('$task instanceof Task ? $task : Task::query()->findOrFail($task)', $method);
        $this->assertStringNotContainsString('$task->refresh()', $method);
    }
}
