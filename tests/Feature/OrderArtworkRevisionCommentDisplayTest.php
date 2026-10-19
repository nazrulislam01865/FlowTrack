<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderArtworkRevisionCommentDisplayTest extends TestCase
{
    public function test_artwork_revision_comment_is_persisted_against_the_reopened_upload_task_and_rendered_below_it(): void
    {
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $jobs = $this->jobServiceSource();
        $row = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $card = file_get_contents(resource_path('views/components/jobs/order-detail/artwork-revision-card.blade.php'));

        $this->assertStringContainsString("'revision_comment' => \$comment", $actions);
        $this->assertStringContainsString("'target_task_id' => (int) \$upload->id", $actions);
        $this->assertStringContainsString("'reference_document_id' =>", $actions);
        $this->assertStringContainsString('hydrateArtworkRevisionNotes($job)', $jobs);
        $this->assertStringContainsString("where('event', 'job.artwork_revision_requested')", $jobs);
        $this->assertStringContainsString("'artworkRevisionNotes'", $jobs);
        $this->assertStringContainsString("setRelation('referenceDocument'", $jobs);
        $this->assertStringContainsString('x-jobs.order-detail.artwork-revision-card', $row);
        $this->assertStringContainsString('Artwork revision issue', $card);
        $this->assertStringContainsString('$revisionComment', $card);
        $this->assertStringContainsString('Artwork selected for revision', $card);
    }

    public function test_artwork_uploads_use_continuous_versions_and_resolved_revision_panel_is_hidden(): void
    {
        $documents = file_get_contents(app_path('Services/DocumentService.php'));
        $jobs = $this->jobServiceSource();
        $row = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $card = file_get_contents(resource_path('views/components/jobs/order-detail/artwork-revision-card.blade.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));

        $this->assertStringContainsString("=== 'ART_PREPARE_UPLOAD'", $documents);
        $this->assertStringContainsString('nextDocumentVersion(', $documents);
        $this->assertStringContainsString('$hasReplacementArtwork', $jobs);
        $this->assertStringContainsString('if ($hasReplacementArtwork)', $jobs);
        $this->assertStringContainsString('ArtworkDocumentName::versioned', $documents);
        $this->assertStringContainsString('? $latestArtworkDocuments', $row);
        $this->assertStringContainsString("class=\"ft-order-task-resource-row {{ \$isArtworkUploadTask ? 'is-latest-artwork' : '' }}\"", $row);
        $this->assertStringContainsString("· Latest", $row);
        $this->assertStringContainsString('ft-order-artwork-version-state', $row);
        $this->assertStringNotContainsString('{{ $taskDocuments->count() - 1 }} archived', $row);
        $this->assertStringContainsString('if (! $isArtworkTask) {', $documents);
        $this->assertStringContainsString("\$query->where('name', \$document->name);", $documents);
        $this->assertStringNotContainsString('· Version {{ max(1, (int) $revisionDocument->version) }}', $card);
        $this->assertStringNotContainsString('{{ $doc->name }} · Version {{ max(1, (int) $doc->version) }}', $modal);
        $this->assertStringContainsString('Previous artwork versions', $modal);
        $this->assertStringContainsString("'artwork_batch_version'", $documents);
        $this->assertStringContainsString('public function storeMany(', $documents);
    }
}
