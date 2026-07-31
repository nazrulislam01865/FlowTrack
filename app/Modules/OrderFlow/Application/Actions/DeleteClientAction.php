<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteClientAction
{
    public function execute(Client $client): void
    {
        $jobs = $client->jobs()->withTrashed()->get();
        $documents = FlowDocument::query()
            ->withTrashed()
            ->whereIn('flow_job_id', $jobs->pluck('id'))
            ->with('versions:id,flow_document_id,disk,path')
            ->get();

        $storedFiles = $documents
            ->flatMap(fn (FlowDocument $document) => $document->versions)
            ->map(fn ($version): array => ['disk' => $version->disk, 'path' => $version->path])
            ->filter(fn (array $file): bool => filled($file['disk']) && filled($file['path']))
            ->unique(fn (array $file): string => $file['disk'].'|'.$file['path'])
            ->values();

        DB::transaction(function () use ($client, $jobs, $documents): void {
            foreach ($documents as $document) {
                $document->versions()->delete();
                $document->forceDelete();
            }

            foreach ($jobs as $job) {
                $job->tasks()->delete();
                $job->delete();
            }

            $client->delete();
        });

        foreach ($storedFiles as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
