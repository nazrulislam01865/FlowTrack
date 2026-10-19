<?php

namespace Tests\Feature;

use Tests\TestCase;

class SetupDeletePerformanceTest extends TestCase
{
    public function test_realtime_notification_delivery_is_queued_instead_of_calling_pusher_in_the_live_request(): void
    {
        $service = file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringContainsString('DeliverRealtimeNotification::dispatch(', $service);
        $this->assertStringContainsString("queue_connection', 'database'", $service);
        $this->assertStringNotContainsString('->triggerUser(', $service);
    }

    public function test_task_pack_delete_modal_uses_its_own_render_branch(): void
    {
        $component = file_get_contents(app_path('Livewire/TaskPackSetup/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/task-pack-setup/index.blade.php'));

        $this->assertStringContainsString('if ($this->showPackDeleteModal)', $component);
        $this->assertStringContainsString('taskPackListData()', $component);
        $this->assertStringContainsString('emptyPageData()', $component);
        $this->assertStringContainsString('@if(!$showPackDeleteModal)', $view);
    }

    public function test_workflow_delete_modal_uses_its_own_render_branch(): void
    {
        $component = file_get_contents(app_path('Livewire/WorkflowSetup/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/workflow-setup/index.blade.php'));

        $this->assertStringContainsString('if ($this->showWorkflowDeleteModal)', $component);
        $this->assertStringContainsString('workflowPageData()', $component);
        $this->assertStringContainsString('emptyPageData()', $component);
        $this->assertStringContainsString('@if(!$showWorkflowDeleteModal)', $view);
    }

    public function test_delete_actions_convert_unexpected_failures_to_user_facing_messages(): void
    {
        $taskPack = file_get_contents(app_path('Livewire/TaskPackSetup/Index.php'));
        $workflow = file_get_contents(app_path('Livewire/WorkflowSetup/Index.php'));

        $this->assertStringContainsString('This Task Pack could not be deleted right now.', $taskPack);
        $this->assertStringContainsString('This Workflow could not be deleted right now.', $workflow);
        $this->assertStringContainsString('catch (\\Throwable $e)', $taskPack);
        $this->assertStringContainsString('catch (\\Throwable $e)', $workflow);
    }
}
