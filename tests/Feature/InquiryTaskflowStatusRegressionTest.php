<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryTaskflowStatusRegressionTest extends TestCase
{
    public function test_inquiry_status_does_not_regress_to_todo_after_taskflow_has_progressed(): void
    {
        $service = $this->inquiryServiceSource();
        $migration = file_get_contents(database_path('migrations/2026_08_17_145500_prevent_inquiry_status_regression_after_task_progress.php'));

        $this->assertStringContainsString('$workingTask = $openTasks->first(', $service);
        $this->assertStringContainsString('$hasProgress = $completed > 0 || $workingTask !== null;', $service);
        $this->assertStringNotContainsString('$task->started_at !== null\n                || $isCompleted($task)', $service);
        $this->assertStringContainsString("if (\$hasProgress && strcasecmp(\$nextStatus, self::AUTO_READY_STATUS) === 0)", $service);
        $this->assertStringContainsString('$nextStatus = self::AUTO_IN_PROGRESS_STATUS;', $service);
        $this->assertStringContainsString('based on overall Inquiry taskflow progress.', $service);

        $this->assertStringContainsString("whereRaw(\"LOWER(TRIM(COALESCE(inquiries.status, ''))) = 'to do'\")", $migration);
        $this->assertStringContainsString("'status' => 'In Progress'", $migration);
        $this->assertStringContainsString("orWhereNotNull('inquiry_tasks.completed_at')", $migration);
        $this->assertStringContainsString("whereNull('inquiry_tasks.completed_at')", $migration);
    }

    public function test_parent_status_color_does_not_use_next_not_started_task_color_after_progress(): void
    {
        $service = $this->inquiryServiceSource();

        $this->assertStringContainsString('if (strcasecmp($record->inquiryAutoStatus(), $autoStatus) === 0)', $service);
    }
}
