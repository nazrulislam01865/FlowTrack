<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteDocumentAction
{
    public function __construct(private readonly ActivityLogger $activities) {}

    /**
     * Permanently remove the document metadata, every version record and every
     * physical file. The Job is returned so workflow readiness can be rebuilt.
     */
    public function execute(FlowDocument $document, ?User $actor = null): ?FlowJob
    {
        $document->loadMissing(['job', 'versions', 'category']);
        $job = $document->job;
        $storedFiles = $document->versions
            ->map(fn ($version): array => ['disk' => $version->disk, 'path' => $version->path])
            ->filter(fn (array $file): bool => filled($file['disk']) && filled($file['path']))
            ->values();

        DB::transaction(function () use ($document, $job, $actor): void {
            if ($job) {
                $this->activities->log(
                    (int) $job->workspace_id,
                    $actor,
                    $job,
                    'job.document_deleted',
                    "Document {$document->code} ({$document->name}) deleted from {$job->code}",
                    [
                        'document_id' => (int) $document->id,
                        'document_code' => (string) $document->code,
                        'document_name' => (string) $document->name,
                        'document_category' => $document->category?->name,
                        'version_count' => $document->versions->count(),
                    ],
                );
            }

            $document->versions()->delete();
            $document->forceDelete();
        });

        $storedFiles->each(function (array $file): void {
            Storage::disk($file['disk'])->delete($file['path']);
        });

        return $job;
    }
}
