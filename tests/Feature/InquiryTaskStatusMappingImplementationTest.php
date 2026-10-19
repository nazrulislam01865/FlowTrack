<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryTaskStatusMappingImplementationTest extends TestCase
{
    public function test_inquiry_task_status_master_data_drives_inquiry_status_and_attention_flags(): void
    {
        $masterService = file_get_contents(app_path('Services/MasterDataService.php'));
        $inquiryService = $this->inquiryServiceSource();
        $masterView = \Tests\Support\AdministrationPhase7Source::masterDataView();
        $taskflowView = file_get_contents(resource_path('views/livewire/inquiries/_taskflow.blade.php'));
        $inquiryView = $this->inquiryViewSource();
        $component = $this->inquiryLivewireSource();
        $migration = file_get_contents(database_path('migrations/2026_08_13_101500_convert_inquiry_status_to_task_status_mapping.php'));

        $this->assertStringContainsString("'inquiry_task_status' => 'Inquiry Task Statuses'", $masterService);
        $this->assertStringNotContainsString("'inquiry_status' => 'Inquiry Statuses'", $masterService);
        $this->assertStringContainsString("active('inquiry_task_status')", $inquiryService);
        $this->assertStringContainsString("public const AUTO_READY_STATUS = 'To do';", $inquiryService);
        $this->assertStringContainsString('autoInquiryStatusForTaskStatus', $inquiryService);
        $this->assertStringContainsString('taskStatusNeedsAttention', $inquiryService);
        $this->assertStringContainsString("'needs_attention' => \$needsAttention", $inquiryService);
        $this->assertStringContainsString('public function syncAutomaticStatus(Inquiry $inquiry', $inquiryService);

        foreach (['IST-005', 'IST-006', 'IST-007', 'IST-008', 'IST-009', 'IST-010', 'IST-011', 'IST-012'] as $code) {
            $this->assertStringContainsString($code, $migration);
        }
        $this->assertStringContainsString("['code' => 'IST-009', 'name' => 'Waiting', 'auto' => 'In Progress', 'attention' => true", $migration);
        $this->assertStringContainsString("['code' => 'IST-012', 'name' => 'Blocked', 'auto' => '__task_status__', 'attention' => true", $migration);
        $this->assertStringContainsString("'inquiry_task_status_id'", $migration);
        $this->assertStringContainsString("'attention_reason'", $migration);

        $this->assertStringContainsString('<th>Inquiry status auto</th><th>Flag</th>', $masterView);
        $this->assertStringContainsString('wire:model="autoInquiryStatus"', $masterView);
        $this->assertStringContainsString('wire:model.boolean="requiresAttention"', $masterView);

        $this->assertStringContainsString('openTaskAttentionReason', $taskflowView);
        $this->assertStringContainsString('class="ft-inquiry-task-flag-icon"', $taskflowView);
        $this->assertStringContainsString("\$task->attention_reason ? 'View or update flag reason' : 'Add flag reason'", $taskflowView);
        $this->assertStringContainsString('showTaskAttentionModal', $inquiryView);
        $this->assertStringContainsString('Why is attention required?', $inquiryView);
        $this->assertStringContainsString('public function saveTaskAttentionReason(): void', $component);
        $this->assertStringContainsString('setTaskAttentionReason', $inquiryService);
        $this->assertStringContainsString("'inquiry.comment'", $inquiryService);
    }
}
