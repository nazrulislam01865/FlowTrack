<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryCompletedAssigneeDisplayTest extends TestCase
{
    public function test_completed_inquiry_list_uses_inquiry_owner_then_creator_instead_of_task_assignee(): void
    {
        $service = $this->inquiryServiceSource();

        $this->assertStringContainsString("'owner:id,name,profile_image_path'", $service);
        $this->assertStringContainsString('$isCompleted = $status === self::AUTO_COMPLETED_STATUS;', $service);
        $this->assertStringContainsString('? ($inquiry->owner ?: $inquiry->creator)', $service);
        $this->assertStringContainsString(': $taskAssignee;', $service);
    }
}
