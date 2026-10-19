<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderArtworkHandoffCommentHistoryImplementationTest extends TestCase
{
    public function test_artwork_handoff_comments_are_persisted_and_visible_after_each_send_cycle(): void
    {
        $service = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $activity = file_get_contents(resource_path('views/components/jobs/order-detail/activity.blade.php'));
        $jobs = file_get_contents(app_path('Services/LegacyJobService.php'));
        $history = file_get_contents(resource_path('views/components/jobs/order-detail/artwork-handoff-comment-history.blade.php'));

        $this->assertStringContainsString("'customer_comment' => \$customerComment !== '' ? \$customerComment : null", $service);
        $this->assertStringContainsString("'comment_history' => \$commentHistory", $service);
        $this->assertStringContainsString("data_get(\$activity->meta, 'customer_comment', '')", $service);

        $this->assertStringContainsString("\$job->relationLoaded('workflowEmailActivities')", $modal);
        $this->assertStringContainsString('Previous customer comments', $modal);
        $this->assertStringContainsString('<x-jobs.order-detail.artwork-handoff-comment-history', $modal);

        $this->assertStringNotContainsString('View customer comment', $taskRow);
        $this->assertStringNotContainsString('<x-jobs.order-detail.artwork-handoff-comment-history', $taskRow);
        $this->assertStringContainsString('CUSTOMER COMMENT', $activity);
        $this->assertStringContainsString('Comment sent with artwork', $activity);
        $this->assertStringContainsString("data_get(\$activity->meta, 'customer_comment', '')", $activity);
        $this->assertStringContainsString("whereNotNull('meta->customer_comment')", $jobs);

        $this->assertStringContainsString('<details class="ft-artwork-handoff-comment-history', $history);
        $this->assertStringContainsString('Latest comment', $history);
        $this->assertStringContainsString('Previous comment', $history);
    }
}
