<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Http\UploadedFile;
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
        $disk = (string) config('filesystems.default');
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
}
