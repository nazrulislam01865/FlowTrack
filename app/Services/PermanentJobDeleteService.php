<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\TaskPackTask;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

class PermanentJobDeleteService
{
    /**
     * Resolve the exact Job records that will be hard-deleted, together with
     * related Task ids and document paths that need non-database cleanup.
     */
    public function snapshot(iterable $jobIds): array
    {
        $requestedIds = collect($jobIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            return ['job_ids' => [], 'task_ids' => [], 'document_paths' => [], 'snapshot_workflow_ids' => [], 'snapshot_task_pack_ids' => []];
        }

        $resolvedJobIds = FlowJob::withTrashed()
            ->whereIn('id', $requestedIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($resolvedJobIds->isEmpty()) {
            return ['job_ids' => [], 'task_ids' => [], 'document_paths' => [], 'snapshot_workflow_ids' => [], 'snapshot_task_pack_ids' => []];
        }

        $taskIds = Task::withTrashed()
            ->whereIn('flow_job_id', $resolvedJobIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $documentPaths = Document::query()
            ->where(function ($query) use ($resolvedJobIds, $taskIds) {
                $query->whereIn('flow_job_id', $resolvedJobIds);
                if ($taskIds->isNotEmpty()) {
                    $query->orWhereIn('task_id', $taskIds);
                }
            })
            ->pluck('path')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $snapshotWorkflowIds = Workflow::query()
            ->where('is_snapshot', true)
            ->whereIn('snapshot_job_id', $resolvedJobIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $snapshotTaskPackIds = TaskPack::query()
            ->where('is_snapshot', true)
            ->whereIn('snapshot_job_id', $resolvedJobIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'job_ids' => $resolvedJobIds->all(),
            'task_ids' => $taskIds->all(),
            'document_paths' => $documentPaths,
            'snapshot_workflow_ids' => $snapshotWorkflowIds,
            'snapshot_task_pack_ids' => $snapshotTaskPackIds,
        ];
    }

    /**
     * Hard-delete a previously resolved Job graph. The caller owns the outer
     * transaction so Workflow/Task Pack deletion remains atomic with Job data.
     */
    public function deleteSnapshot(array $snapshot): void
    {
        $jobIds = collect($snapshot['job_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        if ($jobIds->isEmpty()) return;

        $taskIds = collect($snapshot['task_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();

        // activities is polymorphic and intentionally has no FK, so remove
        // these rows explicitly before the parent records disappear.
        Activity::query()
            ->where('subject_type', FlowJob::class)
            ->whereIn('subject_id', $jobIds)
            ->delete();

        if ($taskIds->isNotEmpty()) {
            Activity::query()
                ->where('subject_type', Task::class)
                ->whereIn('subject_id', $taskIds)
                ->delete();
        }

        // flow_jobs is the root of the operational graph. Database FKs cascade
        // tasks, documents, notifications, members, items, phase histories,
        // comments and checklist items. Using the query builder bypasses the
        // SoftDeletes scope and permanently removes both live and trashed Jobs.
        DB::table('flow_jobs')->whereIn('id', $jobIds)->delete();
        app(WorkspaceRefreshService::class)->touch('FlowJob:force-deleted');

        $snapshotWorkflowIds = collect($snapshot['snapshot_workflow_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        if ($snapshotWorkflowIds->isNotEmpty()) {
            Workflow::query()->whereIn('id', $snapshotWorkflowIds)->where('is_snapshot', true)->delete();
        }

        $snapshotTaskPackIds = collect($snapshot['snapshot_task_pack_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        if ($snapshotTaskPackIds->isNotEmpty()) {
            TaskPackTask::query()->whereIn('task_pack_id', $snapshotTaskPackIds)->delete();
            TaskPackItem::query()->whereIn('task_pack_id', $snapshotTaskPackIds)->delete();
            TaskPack::query()->whereIn('id', $snapshotTaskPackIds)->where('is_snapshot', true)->delete();
        }
    }

    /**
     * Delete physical document files only after the DB transaction commits and
     * only when no remaining document record still shares the same path.
     */
    public function cleanupDocumentFiles(array $paths): void
    {
        foreach (collect($paths)->filter()->unique() as $path) {
            if (!Document::query()->where('path', $path)->exists()) {
                app(SecureDocumentStorage::class)->delete((string) $path);
            }
        }
    }
}
