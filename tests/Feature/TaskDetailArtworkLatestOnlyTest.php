<?php

namespace Tests\Feature;

use Tests\TestCase;

class TaskDetailArtworkLatestOnlyTest extends TestCase
{
    public function test_task_details_artwork_attachments_show_every_file_in_latest_revision(): void
    {
        $attachments = file_get_contents(resource_path('views/components/jobs/task-detail/attachments.blade.php'));
        $documents = file_get_contents(app_path('Services/DocumentService.php'));

        $this->assertStringContainsString("\$taskAutomationKey === 'ART_PREPARE_UPLOAD'", $attachments);
        $this->assertStringContainsString('currentArtworkDocuments(', $attachments);
        $this->assertStringContainsString('? $latestArtworkDocuments', $attachments);
        $this->assertStringContainsString('@foreach($visibleTaskDocuments as $doc)', $attachments);
        $this->assertStringNotContainsString('· Version {{ max(1, (int) $doc->version) }}', $attachments);
        $this->assertStringContainsString('· Latest', $attachments);
        $this->assertStringContainsString('Older artwork revisions remain available in document/version history.', $attachments);

        // Normal Artwork batches still share a version; selective replacements
        // increment only the file that was actually revised.
        $this->assertStringContainsString("=== 'ART_PREPARE_UPLOAD'", $documents);
        $this->assertStringContainsString('if (! $isArtworkTask) {', $documents);
    }
}
