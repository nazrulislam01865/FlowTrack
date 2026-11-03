<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Support\OrderDetailPresenter;
use Tests\TestCase;

class OrderWorkflowSampleBranchRegressionTest extends TestCase
{
    public function test_untouched_optional_sample_task_is_not_treated_as_activated(): void
    {
        $task = new Task(['status' => 'Not Ready', 'progress' => 0]);
        $this->assertFalse(OrderDetailPresenter::isConditionalTaskActivated($task));

        $task->status = 'Ready';
        $this->assertTrue(OrderDetailPresenter::isConditionalTaskActivated($task));

        $task->status = 'Skipped';
        $this->assertFalse(OrderDetailPresenter::isConditionalTaskActivated($task));
    }

    public function test_no_sample_path_skips_sample_and_keeps_phase_advance_enabled(): void
    {
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $presenter = file_get_contents(app_path('Support/OrderDetailPresenter.php'));
        $jobPresenter = file_get_contents(app_path('Support/JobDetailPresenter.php'));
        $jobs = $this->jobServiceSource();
        // The phase-advance action lives in the lazy Workflow child, not the old parent coordinator.
        $workflowSection = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));

        $this->assertStringContainsString("\$key === 'ART_CLIENT_ERP_DECISION' && \$decision === 'confirm'", $actions);
        $this->assertStringContainsString("'status' => 'Skipped'", $actions);
        $this->assertStringContainsString("'event' => 'job.sample_not_required'", $actions);
        $this->assertStringContainsString('return $this->complete($locked, $actor);', $actions);

        $this->assertStringContainsString("['', 'not start', 'not started', 'not ready', 'locked']", $presenter);
        $this->assertStringContainsString('self::isConditionalTaskActivated($task)', $presenter);
        $this->assertStringContainsString('OrderDetailPresenter::isSkippedTask($task)', $jobPresenter);
        // JobDetailPresenter delegates conditional activation to OrderDetailPresenter.
        // Verify the shared runtime rule rather than requiring a duplicated status list.
        foreach (['', 'Not Start', 'Not Started', 'Not Ready', 'Locked'] as $status) {
            $this->assertFalse(OrderDetailPresenter::isConditionalTaskActivated(
                new Task(['status' => $status, 'progress' => 0]),
            ));
        }
        $this->assertStringContainsString('$isPrototypeOrderRuntime', $jobs);
        $this->assertStringContainsString('filled($workflowActions->automationKey($task))', $jobs);
        $this->assertStringContainsString('fn () => app(AutoAdvanceOrder::class)->handle($runtimeJob, auth()->user())', $workflowSection);
    }
}
