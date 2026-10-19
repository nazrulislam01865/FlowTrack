<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryIntelligenceServiceRegressionTest extends TestCase
{
    public function test_portfolio_evidence_kpi_uses_the_calculated_submission_evidence_count(): void
    {
        $service = file_get_contents(app_path('Services/InquiryIntelligenceService.php'));

        $this->assertStringContainsString('$submissionWithEvidence = $submissionTasks', $service);
        $this->assertStringContainsString("'evidenced' => \$submissionWithEvidence", $service);
        $this->assertStringNotContainsString('$submissionWithFiles', $service);
    }


    public function test_task_duration_uses_lifecycle_status_history_instead_of_zero_length_completion_timestamps(): void
    {
        $service = file_get_contents(app_path('Services/InquiryIntelligenceService.php'));

        $this->assertStringContainsString('private function taskLifecycleStats', $service);
        $this->assertStringContainsString("'inquiry.task_status_changed'", $service);
        $this->assertStringContainsString("'inquiry.task_completed'", $service);
        $this->assertStringContainsString('isWorkingTaskStatus($toStatus)', $service);
        $this->assertStringContainsString('max(1, $startedAt->diffInSeconds($at))', $service);
        $this->assertStringContainsString('equal timestamps are a direct-completion artifact', $service);
    }

    public function test_inquiry_task_status_activities_store_structured_status_metadata_for_reporting(): void
    {
        $service = file_get_contents(app_path('Services/LegacyInquiryService.php'));

        $this->assertStringContainsString("'old_status' => \$oldStatus", $service);
        $this->assertStringContainsString("'to_status' => \$status", $service);
        $this->assertStringContainsString("'to_status' => (string) \$task->status", $service);
    }

}
