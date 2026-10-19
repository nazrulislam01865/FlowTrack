<?php

namespace App\Services;

use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\TaskLink;
use Illuminate\Support\Str;

/**
 * Repairs artwork evidence that became detached from the current generated
 * Artwork upload task after an Order workflow / Task Pack definition changed.
 *
 * Runtime generated Tasks are soft-deleted when their setup identity changes,
 * while Documents and TaskLinks intentionally remain for audit/history. Older
 * Orders can therefore have valid artwork files whose task_id still points at a
 * retired Task. This service safely rebinds only historical/orphan artwork
 * evidence to the one active ART_PREPARE_UPLOAD task for the same Order.
 */
final class OrderArtworkEvidenceService
{
    public function repair(int $jobId): int
    {
        $job = FlowJob::query()->find($jobId);
        if (! $job) {
            return 0;
        }

        // Most Orders are already healthy. Avoid hydrating every active task,
        // its setup/category relations, historical tasks and artwork versions
        // unless there is actual detached evidence to repair. These two EXISTS
        // checks match the candidate sets used below and keep the normal Order
        // Details read path cheap while preserving the exact repair fallback for
        // legacy/orphaned files and links.
        $hasDetachedDocuments = Document::query()
            ->where('flow_job_id', $jobId)
            ->where(function ($query) use ($jobId): void {
                $query->whereNull('task_id')
                    ->orWhereNotIn(
                        'task_id',
                        Task::query()
                            ->select('id')
                            ->where('flow_job_id', $jobId),
                    );
            })
            ->exists();

        $hasDetachedLinks = TaskLink::query()
            ->whereIn(
                'task_id',
                Task::onlyTrashed()
                    ->select('id')
                    ->where('flow_job_id', $jobId),
            )
            ->exists();

        if (! $hasDetachedDocuments && ! $hasDetachedLinks) {
            return 0;
        }

        $activeTasks = Task::query()
            ->where('flow_job_id', $jobId)
            ->with([
                'setupTemplate.documentCategory',
                'documentCategory',
                'phase:id,name,sequence',
            ])
            ->get();

        /** @var Task|null $artworkTask */
        $artworkTask = $activeTasks->first(
            fn (Task $task): bool => $this->isArtworkUploadTask($task)
        );

        if (! $artworkTask) {
            return 0;
        }

        $activeTaskIds = $activeTasks
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $candidateDocuments = Document::query()
            ->where('flow_job_id', $jobId)
            ->where(function ($query) use ($activeTaskIds): void {
                $query->whereNull('task_id');
                if ($activeTaskIds->isNotEmpty()) {
                    $query->orWhereNotIn('task_id', $activeTaskIds->all());
                }
            })
            ->orderBy('id')
            ->get();

        $candidateLinks = TaskLink::query()
            ->whereIn(
                'task_id',
                Task::onlyTrashed()
                    ->where('flow_job_id', $jobId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            )
            ->get();

        $historicalTaskIds = $candidateDocuments
            ->pluck('task_id')
            ->merge($candidateLinks->pluck('task_id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $historicalTasks = $historicalTaskIds->isEmpty()
            ? collect()
            : Task::withTrashed()
                ->where('flow_job_id', $jobId)
                ->whereIn('id', $historicalTaskIds->all())
                ->with([
                    'setupTemplate.documentCategory',
                    'documentCategory',
                    'phase:id,name,sequence',
                ])
                ->get()
                ->keyBy(fn (Task $task) => (int) $task->id);

        $artworkCategory = $this->normalizedCategory($artworkTask);
        $historicalArtworkTaskIds = $historicalTasks
            ->filter(fn (Task $task): bool => $this->isArtworkUploadTask($task))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values();

        $movedDocuments = 0;
        $artworkBatchKeys = Document::query()
            ->where('task_id', $artworkTask->id)
            ->get(['id', 'version'])
            ->mapWithKeys(fn (Document $document): array => [
                (int) $document->id => 'active:'.max(1, (int) $document->version),
            ])
            ->all();

        foreach ($candidateDocuments as $document) {
            $sourceTaskId = (int) ($document->task_id ?? 0);
            $sourceTask = $sourceTaskId > 0
                ? $historicalTasks->get($sourceTaskId)
                : null;

            $belongsToArtwork = $sourceTask
                ? $this->isArtworkUploadTask($sourceTask)
                : false;

            // Compatibility fallback for old Orders whose retired task no
            // longer has a resolvable Task Pack item/title. The Artwork task
            // has a dedicated document category, so an orphan document with
            // that exact category can be safely restored to it.
            if (! $belongsToArtwork && $artworkCategory !== '') {
                $belongsToArtwork = $this->normalize((string) $document->category) === $artworkCategory;
            }

            if (! $belongsToArtwork) {
                continue;
            }

            $artworkBatchKeys[(int) $document->id] = $sourceTaskId > 0
                ? 'historical:'.$sourceTaskId.':'.max(1, (int) $document->version)
                : 'orphan:'.max(1, (int) $document->version).':'.(string) $document->created_at;
            $document->update(['task_id' => (int) $artworkTask->id]);
            $movedDocuments++;
        }

        $movedLinks = 0;
        if ($historicalArtworkTaskIds->isNotEmpty()) {
            $movedLinks = TaskLink::query()
                ->whereIn('task_id', $historicalArtworkTaskIds->all())
                ->update(['task_id' => (int) $artworkTask->id]);

            // Revision and cancellation events reference the upload task in
            // activity metadata. Keep those references aligned when a Task Pack
            // change replaces the generated Artwork upload task identity.
            $job->activities()
                ->whereIn('event', ['job.artwork_revision_requested', 'job.artwork_cancelled'])
                ->get()
                ->each(function ($activity) use ($historicalArtworkTaskIds, $artworkTask): void {
                    $meta = is_array($activity->meta) ? $activity->meta : [];
                    $targetTaskId = (int) ($meta['target_task_id'] ?? 0);

                    if (! $historicalArtworkTaskIds->contains($targetTaskId)) {
                        return;
                    }

                    $meta['target_task_id'] = (int) $artworkTask->id;
                    $activity->update(['meta' => $meta]);
                });
        }

        $changed = $movedDocuments + (int) $movedLinks;
        if ($changed > 0) {
            $this->normalizeArtworkVersions((int) $artworkTask->id, $artworkBatchKeys);
            app(WorkspaceRefreshService::class)->touch('OrderArtworkEvidence:repaired');
        }

        return $changed;
    }

    private function isArtworkUploadTask(Task $task): bool
    {
        $key = trim((string) app(OrderWorkflowActionService::class)->automationKey($task));
        if ($key === 'ART_PREPARE_UPLOAD') {
            return true;
        }

        $title = $this->normalize((string) $task->title);

        return in_array($title, [
            'prepare upload artwork',
            'prepare and upload artwork',
            'prepare artwork',
            'upload artwork',
            'artwork upload',
        ], true)
            || (str_contains($title, 'artwork') && str_contains($title, 'upload'));
    }

    private function normalizedCategory(Task $task): string
    {
        return $this->normalize((string) (
            $task->documentCategory?->name
            ?: $task->setupTemplate?->documentCategory?->name
            ?: ''
        ));
    }

    private function normalize(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->replace('&', ' and ')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /** @param array<int,string> $batchKeys */
    private function normalizeArtworkVersions(int $taskId, array $batchKeys = []): void
    {
        $versionsByBatch = [];
        $nextVersion = 0;

        Document::query()
            ->where('task_id', $taskId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'version'])
            ->values()
            ->each(function (Document $document) use (&$versionsByBatch, &$nextVersion, $batchKeys): void {
                $batchKey = $batchKeys[(int) $document->id]
                    ?? 'active:'.max(1, (int) $document->version);
                $version = $versionsByBatch[$batchKey] ??= ++$nextVersion;
                if ((int) $document->version !== $version) {
                    $document->update(['version' => $version]);
                }
            });
    }
}
