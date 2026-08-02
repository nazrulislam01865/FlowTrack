<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class UploadJobAttachmentAction
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function execute(FlowJob $job, UploadedFile $file, User $actor): FlowDocument
    {
        $job->loadMissing('currentPhase');
        $disk = (string) config('flowtrack.documents_disk', 'flowtrack_documents');
        $storedPath = null;

        try {
            return DB::transaction(function () use ($job, $file, $actor, $disk, &$storedPath): FlowDocument {
                $document = FlowDocument::create([
                    'workspace_id' => $job->workspace_id,
                    'flow_job_id' => $job->id,
                    'workflow_phase_id' => $job->current_phase_id,
                    'task_pack_item_id' => null,
                    'document_category_id' => null,
                    'uploaded_by' => $actor->id,
                    'linked_by' => $actor->id,
                    'linked_at' => now(),
                    'code' => $this->identifiers->document(),
                    'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'is_final' => false,
                ]);

                $storedPath = $file->store(
                    "flowtrack/{$job->code}/attachments/{$document->code}",
                    $disk,
                );

                if (! is_string($storedPath) || $storedPath === '') {
                    throw new RuntimeException('The attachment could not be stored.');
                }

                $document->versions()->create([
                    'uploaded_by' => $actor->id,
                    'version' => 1,
                    'disk' => $disk,
                    'path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'notes' => 'Uploaded while creating the Job.',
                ]);

                $this->activityLogger->log(
                    $job->workspace_id,
                    $actor,
                    $document,
                    'job.attachment_uploaded',
                    "{$file->getClientOriginalName()} attached to {$job->code} during Job creation",
                    [
                        'flow_job_id' => $job->id,
                        'workflow_phase_id' => $job->current_phase_id,
                        'source' => 'job_creation',
                    ],
                );

                return $document->fresh(['phase', 'versions.uploader']);
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk($disk)->delete($storedPath);
            }

            throw $exception;
        }
    }
    public function copyExisting(FlowJob $job, FlowDocument $source, User $actor): FlowDocument
    {
        $source->loadMissing('currentVersion');
        $version = $source->currentVersion;
        if (! $version || ! Storage::disk($version->disk)->exists($version->path)) {
            throw ValidationException::withMessages([
                'existing_attachment_document_ids' => 'One of the selected stored documents is no longer available.',
            ]);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'flowtrack-attachment-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('The stored document could not be prepared for attachment.');
        }

        $sourceStream = null;
        $targetStream = null;
        try {
            $sourceStream = Storage::disk($version->disk)->readStream($version->path);
            $targetStream = fopen($temporaryPath, 'w+b');
            if (! is_resource($sourceStream) || ! is_resource($targetStream)) {
                throw new RuntimeException('The stored document could not be read.');
            }
            stream_copy_to_stream($sourceStream, $targetStream);
            fclose($sourceStream);
            fclose($targetStream);
            $sourceStream = null;
            $targetStream = null;

            $uploadedFile = new UploadedFile(
                $temporaryPath,
                $version->original_name ?: ($source->name ?: 'document'),
                $version->mime_type ?: null,
                null,
                true,
            );

            return $this->execute($job, $uploadedFile, $actor);
        } finally {
            if (is_resource($sourceStream)) fclose($sourceStream);
            if (is_resource($targetStream)) fclose($targetStream);
            @unlink($temporaryPath);
        }
    }

}
