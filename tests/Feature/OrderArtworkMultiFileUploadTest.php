<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderArtworkMultiFileUploadTest extends TestCase
{
    public function test_order_artwork_upload_accepts_and_persists_a_file_set_as_one_revision(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/document-modal.blade.php'));
        $jobComponent = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $jobUploads = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderTaskResources.php'));
        $orderList = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $documents = file_get_contents(app_path('Services/DocumentService.php'));
        $chunkUpload = file_get_contents(resource_path('js/components/artwork-chunk-upload.js'));
        $staging = file_get_contents(app_path('Services/ArtworkUploadStagingService.php'));
        $chunkController = file_get_contents(app_path('Http/Controllers/ArtworkChunkUploadController.php'));
        $flowtrackConfig = file_get_contents(config_path('flowtrack.php'));

        $this->assertStringContainsString("\$chunkedArtworkUpload = in_array(\$automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true)", $modal);
        $this->assertStringContainsString('@if($inputAllowsMultiple) multiple @endif', $modal);
        $this->assertStringContainsString('Up to 10 files', $modal);
        $this->assertStringContainsString('removeOverviewTaskDocumentUpload({{ $index }})', $modal);
        $this->assertStringContainsString('data-artwork-chunk-input', $modal);
        $this->assertStringContainsString('data-revision-document-id="{{ $revisionDocumentId }}"', $modal);
        $this->assertStringNotContainsString('wire:model="overviewTaskRevisionUpload.{{ $revisionDocumentId }}"', $modal);
        $this->assertStringContainsString('Upload the corrected file for this artwork only.', $modal);

        $this->assertStringContainsString('public array $overviewTaskDocumentUpload = [];', $jobComponent);
        foreach ([$jobUploads, $orderList] as $component) {
            $this->assertStringContainsString('overviewTaskStagedUploads', $component);
            $this->assertStringContainsString('registerOverviewTaskArtworkUploads(', $component);
            $this->assertStringContainsString('materialize($stagedTokens', $component);
            $this->assertStringContainsString("'overviewTaskDocumentUpload.*' => AttachmentUpload::itemRules", $component);
            $this->assertStringContainsString('$uploads = $isArtworkRevision ? $this->overviewTaskRevisionUpload : $this->overviewTaskDocumentUpload;', $component);
            $this->assertStringContainsString('storeMany($uploads', $component);
            $this->assertStringContainsString('storeArtworkRevision(', $component);
            $this->assertStringContainsString('catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception)', $component);
            $this->assertStringContainsString("\$isArtworkRevision ? 'overviewTaskRevisionUpload' : 'overviewTaskDocumentUpload'", $component);
            $this->assertStringContainsString('function removeOverviewTaskDocumentUpload(', $component);
        }

        $this->assertStringContainsString('public function storeMany(', $documents);
        $this->assertStringContainsString('mapArtworkRevisionReplacementFiles(', $documents);
        $this->assertStringContainsString('return DB::transaction(function () use ($files, $data, $user, $permissionModule, $securityMaxBytes, &$storedPaths)', $documents);
        $this->assertStringContainsString("\$fileData['artwork_batch_version'] = \$artworkBatchVersion", $documents);
        $this->assertStringContainsString('$version = $isArtworkTask && $batchVersion > 0', $documents);
        $this->assertStringContainsString('const DEFAULT_CHUNK_BYTES = 15 * 1024 * 1024;', $chunkUpload);
        $this->assertStringContainsString('const DEFAULT_CHUNK_CONCURRENCY = 3;', $chunkUpload);
        $this->assertStringContainsString('const workerCount = Math.min(chunkCount, concurrency);', $chunkUpload);
        $this->assertStringContainsString('signal: controller.signal', $chunkUpload);
        $this->assertStringContainsString('registerOverviewTaskArtworkRevisionUpload', $chunkUpload);
        $this->assertStringContainsString('requestJson(start.chunk_url', $chunkUpload);
        $this->assertStringContainsString("private const ROOT = 'staged-artwork';", $staging);
        $this->assertStringContainsString('AttachmentUpload::ARTWORK_MAX_BYTES', $staging);
        $this->assertStringContainsString('public function chunkConcurrency(): int', $staging);
        $this->assertStringContainsString("config('flowtrack.artwork_chunk_upload.concurrency', 3)", $staging);
        $this->assertStringContainsString("array_key_exists(\$key, \$chunkSizes)", $staging);
        $this->assertStringNotContainsString('Artwork chunks must arrive in order.', $staging);
        $this->assertStringContainsString("'chunk_concurrency' => \$staging->chunkConcurrency()", $chunkController);
        $this->assertStringContainsString("FLOWTRACK_ARTWORK_CHUNK_BYTES', 15728640", $flowtrackConfig);
        $this->assertStringContainsString("FLOWTRACK_ARTWORK_CHUNK_CONCURRENCY', 3", $flowtrackConfig);
    }

    public function test_latest_artwork_batch_is_visible_and_all_files_are_emailed(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $taskAttachments = file_get_contents(resource_path('views/components/jobs/task-detail/attachments.blade.php'));
        $workflowModal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $emailService = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));

        $this->assertStringContainsString('currentArtworkDocuments', $row);
        $this->assertStringContainsString('? $latestArtworkDocuments', $row);
        $this->assertStringContainsString('currentArtworkDocuments', $taskAttachments);
        $this->assertStringContainsString('currentArtworkDocuments', $workflowModal);

        $this->assertStringContainsString('private function sourceDocuments(', $emailService);
        $this->assertStringContainsString('currentArtworkDocuments(', $emailService);
        $this->assertStringContainsString('attachments: $attachments', $emailService);
        $this->assertStringContainsString("'document_ids' => \$documents->pluck('id')", $emailService);
    }
}
