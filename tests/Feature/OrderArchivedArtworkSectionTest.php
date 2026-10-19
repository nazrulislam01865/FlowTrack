<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Support\OrderDetailPresenter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class OrderArchivedArtworkSectionTest extends TestCase
{
    public function test_archived_artwork_component_matches_the_order_detail_contract(): void
    {
        $workflow = file_get_contents(resource_path('views/components/jobs/order-detail/workflow.blade.php'));
        $component = file_get_contents(resource_path('views/components/jobs/order-detail/archived-artwork.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/detail/archived-artwork.css'));
        $jobs = file_get_contents(app_path('Services/LegacyJobService.php'));

        $this->assertStringContainsString('archivedArtworkDocuments($job, $selectedTasks)', $workflow);
        $this->assertStringContainsString("setRelation('artworkRevisionAppliedActivities'", $jobs);
        $this->assertStringContainsString('x-jobs.order-detail.archived-artwork', $workflow);
        $this->assertStringContainsString('Archived Artwork', $component);
        $this->assertStringContainsString('View previous versions of artwork that have been replaced.', $component);
        $this->assertStringContainsString('Revision reason', $component);
        $this->assertStringContainsString('artwork_revision_reason', $component);
        $this->assertStringContainsString("setRelation('artworkRevisionRequestActivities'", $jobs);
        $this->assertStringContainsString('<th scope="col">Filename</th>', $component);
        $this->assertStringContainsString('<th scope="col">Version</th>', $component);
        $this->assertStringContainsString('<th scope="col">Uploaded by</th>', $component);
        $this->assertStringContainsString('<th scope="col">Uploaded on</th>', $component);
        $this->assertStringContainsString('route(\'documents.open\'', $component);
        $this->assertStringContainsString('route(\'documents.download\'', $component);
        $this->assertStringContainsString('.ft-order-archived-artwork__table', $css);
    }

    public function test_archived_artwork_presenter_lists_only_files_actually_replaced_by_completed_revisions(): void
    {
        $job = new FlowJob(['id' => 91]);
        $task = new Task(['id' => 22, 'flow_job_id' => 91]);

        $current = new Document(['id' => 403, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'latest.jpg', 'version' => 6]);
        $revisedSource = new Document(['id' => 402, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'revised-source.jpg', 'version' => 5]);
        $olderNormalUpload = new Document(['id' => 401, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'older-normal-upload.jpg', 'version' => 4]);
        $otherTaskDocument = new Document(['id' => 500, 'flow_job_id' => 91, 'task_id' => 23, 'name' => 'other.pdf', 'version' => 1]);

        $applied = new Activity([
            'event' => 'job.artwork_revision_applied',
            'meta' => [
                'target_task_id' => 22,
                'replaced_source_document_ids' => [402, 402],
                'replacement_document_map' => ['402' => 403],
            ],
        ]);
        $duplicateAppliedEvent = new Activity([
            'event' => 'job.artwork_revision_applied',
            'meta' => [
                'target_task_id' => 22,
                'replaced_source_document_ids' => [402],
            ],
        ]);

        $task->setRelation('currentArtworkDocuments', new Collection([$current]));
        $job->setRelation('documents', new Collection([$olderNormalUpload, $otherTaskDocument, $current, $revisedSource]));
        $job->setRelation('artworkRevisionAppliedActivities', new Collection([$duplicateAppliedEvent, $applied]));

        $archived = OrderDetailPresenter::archivedArtworkDocuments($job, new Collection([$task]));

        $this->assertSame([402], $archived->pluck('id')->all());
    }

    public function test_archived_artwork_stays_empty_until_a_revision_upload_is_applied(): void
    {
        $job = new FlowJob(['id' => 91]);
        $task = new Task(['id' => 22, 'flow_job_id' => 91]);
        $current = new Document(['id' => 403, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'latest.jpg', 'version' => 2]);
        $olderNormalUpload = new Document(['id' => 401, 'flow_job_id' => 91, 'task_id' => 22, 'name' => 'first-upload.jpg', 'version' => 1]);

        $task->setRelation('currentArtworkDocuments', new Collection([$current]));
        $job->setRelation('documents', new Collection([$olderNormalUpload, $current]));
        $job->setRelation('artworkRevisionAppliedActivities', collect());

        $archived = OrderDetailPresenter::archivedArtworkDocuments($job, new Collection([$task]));

        $this->assertTrue($archived->isEmpty());
    }
}
