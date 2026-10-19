<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Support\OrderDetailPresenter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderArtworkCancellationTest extends TestCase
{
    public function test_internal_artwork_review_exposes_individual_cancellation_with_optional_product_removal(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $component = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $workflow = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $cancellation = file_get_contents(app_path('Services/OrderArtworkCancellationService.php'));

        $this->assertStringContainsString("'cancel_artwork' => 'Cancel Artwork'", $workflow);
        $this->assertStringContainsString("'cancel_artwork_document_ids' => []", $workflow);
        $this->assertStringContainsString("'cancel_product_item_ids' => []", $workflow);
        $this->assertStringContainsString("\$this->orderWorkflowActionStep = 'cancel_artwork'", $component);
        $this->assertStringContainsString('wire:model.live="orderWorkflowActionPayload.cancel_artwork_document_ids"', $modal);
        $this->assertStringContainsString('Remove product with artwork <span>(optional)</span>', $modal);
        $this->assertStringContainsString('wire:model.live="orderWorkflowActionPayload.cancel_product_item_ids"', $modal);
        $this->assertStringContainsString('Cancellation reason <b>*</b>', $modal);
        $this->assertStringContainsString("'event' => 'job.artwork_cancelled'", $cancellation);
        $this->assertStringContainsString("'is_removed' => true", $cancellation);
        $this->assertStringContainsString('$this->itemSummary->sync($order)', $cancellation);
    }

    public function test_cancelled_artwork_is_removed_from_current_artwork_resolution_and_retained_for_audit(): void
    {
        $documents = file_get_contents(app_path('Services/DocumentService.php'));
        $jobs = file_get_contents(app_path('Services/LegacyJobService.php'));
        $archive = file_get_contents(resource_path('views/components/jobs/order-detail/archived-artwork.blade.php'));

        $this->assertStringContainsString('withoutCancelledArtwork(', $documents);
        $this->assertStringContainsString("where('event', 'job.artwork_cancelled')", $documents);
        $this->assertStringContainsString("setRelation('artworkCancellationActivities'", $jobs);
        $this->assertStringContainsString('Cancelled artwork is retained here for audit history.', $archive);
        $this->assertStringContainsString("\$archiveStatus === 'Cancelled'", $archive);
    }

    public function test_archived_artwork_presenter_marks_cancelled_files_and_products(): void
    {
        $job = (new FlowJob())->forceFill(['id' => 91]);
        $task = (new Task())->forceFill(['id' => 22, 'flow_job_id' => 91]);
        $remaining = (new Document())->forceFill(['id' => 403, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'remaining.jpg', 'version' => 2]);
        $cancelled = (new Document())->forceFill(['id' => 402, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'cancelled.jpg', 'version' => 2]);

        $activity = new Activity([
            'event' => 'job.artwork_cancelled',
            'meta' => [
                'target_task_id' => 22,
                'document_ids' => [402],
                'reason' => 'Client removed this design.',
                'product_item_ids' => [71],
                'product_names' => ['Blue T-Shirt'],
            ],
        ]);

        $task->setRelation('currentArtworkDocuments', new Collection([$remaining]));
        $job->setRelation('documents', new Collection([$cancelled, $remaining]));
        $job->setRelation('artworkRevisionAppliedActivities', collect());
        $job->setRelation('artworkRevisionRequestActivities', collect());
        $job->setRelation('artworkCancellationActivities', new Collection([$activity]));

        $archive = OrderDetailPresenter::archivedArtworkDocuments($job, new Collection([$task]));

        $this->assertSame([402], $archive->pluck('id')->all());
        $this->assertSame('Cancelled', $archive->first()->artwork_archive_status);
        $this->assertSame('Cancellation reason', $archive->first()->artwork_archive_reason_label);
        $this->assertSame('Client removed this design.', $archive->first()->artwork_revision_reason);
        $this->assertSame(['Blue T-Shirt'], $archive->first()->artwork_cancelled_product_names);
    }
}
