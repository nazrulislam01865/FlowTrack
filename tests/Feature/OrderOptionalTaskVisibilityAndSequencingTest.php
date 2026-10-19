<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskPackItem;
use App\Support\OrderTaskRequirement;
use Tests\TestCase;

class OrderOptionalTaskVisibilityAndSequencingTest extends TestCase
{
    public function test_production_issue_is_a_regular_optional_task_not_a_conditional_branch(): void
    {
        $task = new Task(['title' => 'Monitor / Resolve Production Issue']);
        $task->setRelation('setupTemplate', new TaskPackItem([
            'is_required' => false,
            'automation_key' => 'PROD_ISSUE',
            'sort_order' => 3,
        ]));

        $this->assertTrue(OrderTaskRequirement::isOptional($task));
        $this->assertTrue(OrderTaskRequirement::isRegularOptional($task));
        $this->assertFalse(OrderTaskRequirement::isConditionalOptional($task));
    }

    public function test_sample_approval_remains_a_hidden_until_activated_conditional_branch(): void
    {
        $task = new Task(['title' => 'Sample Approval (when required)']);
        $task->setRelation('setupTemplate', new TaskPackItem([
            'is_required' => false,
            'automation_key' => 'ART_SAMPLE_APPROVAL',
            'sort_order' => 5,
        ]));

        $this->assertTrue(OrderTaskRequirement::isConditionalOptional($task));
        $this->assertFalse(OrderTaskRequirement::isRegularOptional($task));
    }

    public function test_order_detail_source_keeps_regular_optional_tasks_visible_without_counting_them_as_required(): void
    {
        $presenter = file_get_contents(app_path('Support/OrderDetailPresenter.php'));
        $workflow = file_get_contents(resource_path('views/components/jobs/order-detail/workflow.blade.php'));
        $row = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $sequence = file_get_contents(app_path('Services/OrderTaskSequenceService.php'));

        $this->assertStringContainsString('OrderTaskRequirement::isRegularOptional($task)', $presenter);
        $this->assertStringContainsString('regularOptionalTaskIsActionable', $presenter);
        $this->assertStringContainsString('required complete', $workflow);
        $this->assertStringContainsString('ft-order-optional-task-badge', $row);
        $this->assertStringContainsString('earlierRequiredTasksComplete', $sequence);
    }
}
