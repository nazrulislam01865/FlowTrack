<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UploadDocumentAction
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function execute(FlowJob $job, UploadedFile $file, ?int $categoryId, bool $isFinal, User $actor, ?int $documentId = null, ?string $notes = null): FlowDocument
    {
        return DB::transaction(function () use ($job, $file, $categoryId, $isFinal, $actor, $documentId, $notes) {
            $document = $documentId
                ? FlowDocument::query()->where('flow_job_id', $job->id)->findOrFail($documentId)
                : FlowDocument::create([
                    'workspace_id' => $job->workspace_id,
                    'flow_job_id' => $job->id,
                    'document_category_id' => $categoryId,
                    'uploaded_by' => $actor->id,
                    'code' => $this->identifiers->document(),
                    'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'is_final' => $isFinal,
                ]);

            $version = ((int) $document->versions()->max('version')) + 1;
            $path = $file->store("flowtrack/{$job->code}/documents/{$document->code}", config('filesystems.default'));

            $document->versions()->create([
                'uploaded_by' => $actor->id,
                'version' => $version,
                'disk' => config('filesystems.default'),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'notes' => $notes,
            ]);

            $document->update(['is_final' => $isFinal, 'document_category_id' => $categoryId ?: $document->document_category_id]);
            $this->activityLogger->log($job->workspace_id, $actor, $document, 'document.uploaded', "{$file->getClientOriginalName()} uploaded", ['version' => $version]);

            return $document->fresh(['category', 'versions.uploader']);
        });
    }
}
