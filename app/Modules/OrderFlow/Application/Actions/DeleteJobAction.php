<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteJobAction
{
    public function execute(FlowJob $job): void
    {
        $documents = $job->documents()
            ->withTrashed()
            ->with('versions:id,flow_document_id,disk,path')
            ->get();

        $storedFiles = $documents
            ->flatMap(fn (FlowDocument $document) => $document->versions)
            ->map(fn ($version): array => ['disk' => $version->disk, 'path' => $version->path])
            ->filter(fn (array $file): bool => filled($file['disk']) && filled($file['path']))
            ->unique(fn (array $file): string => $file['disk'].'|'.$file['path'])
            ->values();

        DB::transaction(function () use ($job, $documents): void {
            foreach ($documents as $document) {
                $document->versions()->delete();
                $document->forceDelete();
            }

            $job->tasks()->delete();
            $job->delete();
        });

        foreach ($storedFiles as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
