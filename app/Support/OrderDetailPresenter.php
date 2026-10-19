<?php

namespace App\Support;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\WorkflowPhase;
use Illuminate\Support\Collection;

/**
 * Query-free presentation helpers for the prototype-matched Order Details UI.
 * All relationships used here must be eager-loaded by JobService.
 */
final class OrderDetailPresenter
{
    public static function phases(FlowJob $job): Collection
    {
        return ($job->workflow?->phases ?? collect())
            ->sortBy(fn (WorkflowPhase $phase) => [(int) $phase->sequence, (int) $phase->id])
            ->values();
    }

    public static function phaseState(FlowJob $job, WorkflowPhase $phase): string
    {
        if ($job->completed_at || strcasecmp((string) $job->status, 'Completed') === 0) return 'completed';

        $currentSequence = (int) ($job->phase?->sequence ?? 0);
        $sequence = (int) $phase->sequence;
        if ($sequence < $currentSequence) return 'completed';
        if (strcasecmp((string) $job->status, 'Cancelled') === 0 && $sequence === $currentSequence) return 'cancelled';
        if ($sequence === $currentSequence) return 'active';
        return 'locked';
    }

    public static function phaseProgress(FlowJob $job, WorkflowPhase $phase): int
    {
        if (self::phaseState($job, $phase) === 'completed') return 100;

        $tasks = JobDetailPresenter::phaseTasks($job, $phase);
        if ($tasks->isEmpty()) return self::phaseState($job, $phase) === 'completed' ? 100 : 0;

        $applicable = $tasks->filter(fn (Task $task) => self::isApplicableTask($task));
        if ($applicable->isEmpty()) return self::phaseState($job, $phase) === 'completed' ? 100 : 0;

        $completed = $applicable->filter(fn (Task $task) => self::isCompletedTask($task))->count();
        return (int) round(($completed / $applicable->count()) * 100);
    }

    public static function phaseTasks(FlowJob $job, WorkflowPhase $phase): Collection
    {
        return JobDetailPresenter::phaseTasks($job, $phase);
    }

    public static function currentTasks(FlowJob $job): Collection
    {
        return $job->phase ? self::phaseTasks($job, $job->phase) : collect();
    }

    public static function nextTask(FlowJob $job): ?Task
    {
        if (strcasecmp((string) $job->status, 'Cancelled') === 0) return null;

        $tasks = self::currentTasks($job);
        $required = $tasks->filter(fn (Task $task) => OrderTaskRequirement::isRequired($task));

        $requiredNext = $required->first(fn (Task $task) => ! self::isCompletedTask($task) && ! self::isSkippedTask($task));

        // Client approval can intentionally wait on the optional Sample
        // Approval task. In that branch the waiting required task remains open
        // as a blocker while the sample row becomes the actionable task.
        if ($requiredNext && strcasecmp(trim((string) $requiredNext->status), 'Waiting for Sample Approval') === 0) {
            $sample = $tasks->first(fn (Task $task) =>
                ! self::isCompletedTask($task)
                && ! self::isSkippedTask($task)
                && self::isConditionalTaskActivated($task)
                && str_contains(strtolower((string) $task->title), 'sample approval')
            );
            if ($sample) return $sample;
        }

        if ($requiredNext && strcasecmp(trim((string) $requiredNext->status), 'Waiting for QC Issue Resolution') === 0) {
            $issue = $tasks->first(fn (Task $task) =>
                ! self::isCompletedTask($task)
                && ! self::isSkippedTask($task)
                && str_contains(strtolower((string) $task->title), 'qc issue')
            );
            if ($issue) return $issue;
        }

        if ($requiredNext) return $requiredNext;

        // Do not promote untouched optional/conditional rows to the active
        // action merely because all required tasks are finished. For example,
        // choosing "No" at the artwork sample decision must skip Sample
        // Approval and allow the phase to advance directly to Production.
        $manual = $tasks->first(fn (Task $task) =>
            ! $task->task_pack_task_id
            && ! self::isCompletedTask($task)
            && ! self::isSkippedTask($task)
        );
        if ($manual) return $manual;

        return $tasks->first(fn (Task $task) =>
            OrderTaskRequirement::isConditionalOptional($task)
            && ! self::isCompletedTask($task)
            && ! self::isSkippedTask($task)
            && self::isConditionalTaskActivated($task)
        );
    }

    public static function isCompletedTask(Task $task): bool
    {
        return (bool) $task->completed_at || strcasecmp(trim((string) $task->status), 'Completed') === 0;
    }

    public static function isSkippedTask(Task $task): bool
    {
        return in_array(strtolower(trim((string) $task->status)), ['skipped', 'not applicable', 'n/a'], true);
    }

    /**
     * True conditional Task Pack branches are considered activated only after
     * a backend workflow action moves them out of their untouched locked state
     * (or evidence/progress already exists). Regular optional tasks do not use
     * this activation rule because they stay visible throughout the stage.
     */
    public static function isConditionalTaskActivated(Task $task): bool
    {
        if (self::isSkippedTask($task)) return false;
        if ($task->completed_at || (int) $task->progress > 0) return true;
        if ($task->relationLoaded('documents') && $task->documents->isNotEmpty()) return true;
        if ($task->relationLoaded('links') && $task->links->isNotEmpty()) return true;

        $status = strtolower(trim((string) $task->status));
        return ! in_array($status, ['', 'not start', 'not started', 'not ready', 'locked'], true);
    }

    public static function isApplicableTask(Task $task): bool
    {
        if (self::isSkippedTask($task)) return false;
        if (! $task->task_pack_task_id) return true;

        return OrderTaskRequirement::isRequired($task)
            || (OrderTaskRequirement::isConditionalOptional($task) && self::isConditionalTaskActivated($task));
    }

    public static function taskMode(FlowJob $job, Task $task): string
    {
        if (self::isCompletedTask($task)) return 'done';
        if (strcasecmp((string) $job->status, 'Cancelled') === 0) return 'locked';
        if ((int) $task->workflow_phase_id !== (int) $job->workflow_phase_id) return 'locked';

        // Regular optional tasks remain available in the active stage without
        // becoming blockers. They become actionable once every earlier required
        // task in their configured sequence has been completed. This lets, for
        // example, Production Issue remain available beside Finish Production.
        if (OrderTaskRequirement::isRegularOptional($task)) {
            return self::regularOptionalTaskIsActionable($job, $task) ? 'active' : 'locked';
        }

        $next = self::nextTask($job);
        return $next && (int) $next->id === (int) $task->id ? 'active' : 'locked';
    }

    public static function regularOptionalTaskIsActionable(FlowJob $job, Task $task): bool
    {
        if (! OrderTaskRequirement::isRegularOptional($task)) return false;
        if ((int) $task->workflow_phase_id !== (int) $job->workflow_phase_id) return false;

        $taskSequence = OrderTaskRequirement::sequence($task);

        return self::currentTasks($job)
            ->filter(fn (Task $candidate) =>
                OrderTaskRequirement::isRequired($candidate)
                && OrderTaskRequirement::sequence($candidate) < $taskSequence
            )
            ->every(fn (Task $candidate) => self::isCompletedTask($candidate) || self::isSkippedTask($candidate));
    }


    public static function selectedPhase(FlowJob $job, ?int $phaseId = null): ?WorkflowPhase
    {
        $phases = self::phases($job);
        if ($phases->isEmpty()) return null;

        if ($phaseId) {
            $selected = $phases->firstWhere('id', (int) $phaseId);
            if ($selected && (int) $selected->sequence <= (int) ($job->phase?->sequence ?? 0)) {
                return $selected;
            }
        }

        return $phases->firstWhere('id', (int) $job->workflow_phase_id) ?: $phases->first();
    }

    /**
     * Tasks shown inside the Order Details stage panel.
     *
     * Required tasks and normal optional tasks are always visible in their
     * configured order. True conditional branch tasks stay hidden until the
     * workflow activates them. Optional visibility is therefore independent
     * from whether the task blocks stage completion.
     */
    public static function displayTasksForPhase(FlowJob $job, WorkflowPhase $phase): Collection
    {
        return self::phaseTasks($job, $phase)
            ->filter(function (Task $task): bool {
                if (! $task->task_pack_task_id) return true;
                if (OrderTaskRequirement::isRequired($task)) return true;
                if (OrderTaskRequirement::isRegularOptional($task)) return true;

                return self::isConditionalTaskActivated($task);
            })
            ->sortBy(function (Task $task): array {
                return [
                    $task->task_pack_task_id ? 0 : 1,
                    (int) ($task->setupTemplate?->sort_order ?? $task->template?->sequence ?? 999999),
                    (int) $task->id,
                ];
            })
            ->values();
    }

    public static function phaseOwnerName(FlowJob $job, WorkflowPhase $phase): string
    {
        $task = self::phaseTasks($job, $phase)
            ->sortBy(fn (Task $task) => [(int) ($task->setupTemplate?->sort_order ?? $task->template?->sequence ?? 999999), (int) $task->id])
            ->first(fn (Task $task) => filled($task->assignee?->name));

        return (string) ($task?->assignee?->name ?: $job->coordinator?->name ?: $job->owner?->name ?: 'Unassigned');
    }

    public static function initials(?string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim((string) $name)))
            ->filter()
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials ?: '—';
    }

    public static function phaseStateLabel(FlowJob $job, WorkflowPhase $phase): string
    {
        $state = self::phaseState($job, $phase);
        if ($state === 'completed') return 'Completed';
        if ($state === 'cancelled') return 'Cancelled here';
        if ($state === 'active') return 'Current · action required';

        $previous = self::phases($job)->firstWhere('sequence', (int) $phase->sequence - 1);
        return 'Upcoming · after '.($previous?->name ?: 'previous stage');
    }

    public static function phaseDependencyLabel(FlowJob $job, WorkflowPhase $phase): string
    {
        if ((int) $phase->sequence <= 1) return 'Starts when order is created';
        $previous = self::phases($job)->firstWhere('sequence', (int) $phase->sequence - 1);
        return 'Depends on '.($previous?->name ?: 'previous stage');
    }

    public static function taskDisplayCode(WorkflowPhase $phase, Task $task, int $index): string
    {
        if ($task->task_pack_task_id) {
            return ((int) $phase->sequence).'.'.($index + 1);
        }

        return (string) ($task->task_number ?: str_pad((string) $task->id, 3, '0', STR_PAD_LEFT));
    }

    /**
     * Artwork files that left the current set because they were replaced by a
     * completed revision or explicitly cancelled from the Order.
     *
     * The archive is event-driven rather than "all non-current files". Normal
     * uploads and accepted artwork therefore never appear here accidentally.
     * LegacyJobService hydrates the relevant activities once, keeping this
     * presenter query-free and safe from N+1 queries.
     *
     * @param Collection<int,Task> $phaseTasks
     * @return Collection<int,\App\Models\Document>
     */
    public static function archivedArtworkDocuments(FlowJob $job, Collection $phaseTasks): Collection
    {
        if (! $job->relationLoaded('documents') || $phaseTasks->isEmpty()) {
            return collect();
        }

        $appliedRevisionActivities = $job->relationLoaded('artworkRevisionAppliedActivities')
            ? collect($job->getRelation('artworkRevisionAppliedActivities'))
            : collect();
        $cancellationActivities = $job->relationLoaded('artworkCancellationActivities')
            ? collect($job->getRelation('artworkCancellationActivities'))
            : collect();

        if ($appliedRevisionActivities->isEmpty() && $cancellationActivities->isEmpty()) {
            return collect();
        }

        $artworkTasks = $phaseTasks
            ->filter(fn (Task $task): bool => $task->relationLoaded('currentArtworkDocuments'))
            ->values();

        if ($artworkTasks->isEmpty()) {
            return collect();
        }

        $artworkTaskIds = $artworkTasks
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->flip();

        $currentDocumentIds = $artworkTasks
            ->flatMap(fn (Task $task) => collect($task->getRelation('currentArtworkDocuments'))->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->flip();

        $appliedBySourceDocumentId = collect();
        $replacedDocumentIds = $appliedRevisionActivities
            ->flatMap(function ($activity) use ($artworkTaskIds, $appliedBySourceDocumentId): Collection {
                $meta = (array) ($activity->meta ?? []);
                $targetTaskId = (int) data_get($meta, 'target_task_id', data_get($meta, 'task_id', 0));
                if ($targetTaskId <= 0 || ! $artworkTaskIds->has($targetTaskId)) {
                    return collect();
                }

                $ids = collect(data_get($meta, 'replaced_source_document_ids', []));
                if ($ids->isEmpty()) {
                    $ids = collect(array_keys((array) data_get($meta, 'replacement_document_map', [])));
                }
                if ($ids->isEmpty()) {
                    $ids = collect(data_get($meta, 'source_document_ids', []));
                }

                $ids->each(function ($id) use ($activity, $appliedBySourceDocumentId): void {
                    $documentId = (int) $id;
                    if ($documentId > 0 && ! $appliedBySourceDocumentId->has($documentId)) {
                        $appliedBySourceDocumentId->put($documentId, $activity);
                    }
                });

                return $ids;
            })
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->flip();

        $cancellationByDocumentId = collect();
        $cancelledDocumentIds = $cancellationActivities
            ->flatMap(function ($activity) use ($artworkTaskIds, $cancellationByDocumentId): Collection {
                $meta = (array) ($activity->meta ?? []);
                $targetTaskId = (int) data_get($meta, 'target_task_id', 0);
                if ($targetTaskId <= 0 || ! $artworkTaskIds->has($targetTaskId)) {
                    return collect();
                }

                $ids = collect(data_get($meta, 'document_ids', []));
                $ids->each(function ($id) use ($activity, $cancellationByDocumentId): void {
                    $documentId = (int) $id;
                    if ($documentId > 0 && ! $cancellationByDocumentId->has($documentId)) {
                        $cancellationByDocumentId->put($documentId, $activity);
                    }
                });

                return $ids;
            })
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->flip();

        $archivedDocumentIds = $replacedDocumentIds->keys()
            ->merge($cancelledDocumentIds->keys())
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->flip();

        if ($archivedDocumentIds->isEmpty()) {
            return collect();
        }

        $revisionRequests = $job->relationLoaded('artworkRevisionRequestActivities')
            ? collect($job->getRelation('artworkRevisionRequestActivities'))
            : collect();
        $revisionRequestsById = $revisionRequests->keyBy(fn ($activity) => (int) $activity->id);
        $richText = app(\App\Services\RichTextService::class);

        return $job->documents
            ->filter(fn ($document): bool => $archivedDocumentIds->has((int) $document->id))
            ->filter(fn ($document): bool => $artworkTaskIds->has((int) ($document->task_id ?? 0)))
            ->reject(fn ($document): bool => $currentDocumentIds->has((int) $document->id))
            ->unique(fn ($document) => (int) $document->id)
            ->sortByDesc(fn ($document) => (int) $document->id)
            ->sortByDesc(fn ($document) => max(1, (int) $document->version))
            ->values()
            ->each(function ($document) use ($appliedBySourceDocumentId, $cancellationByDocumentId, $revisionRequests, $revisionRequestsById, $richText): void {
                $documentId = (int) $document->id;
                $cancellation = $cancellationByDocumentId[$documentId] ?? null;

                if ($cancellation) {
                    $reason = trim((string) data_get($cancellation->meta, 'reason', ''));
                    $productNames = collect(data_get($cancellation->meta, 'product_names', []))
                        ->map(fn ($name) => trim((string) $name))
                        ->filter()
                        ->values()
                        ->all();

                    $document->setAttribute('artwork_archive_status', 'Cancelled');
                    $document->setAttribute('artwork_archive_reason_label', 'Cancellation reason');
                    $document->setAttribute('artwork_revision_reason', $richText->plainText($reason));
                    $document->setAttribute('artwork_cancelled_product_names', $productNames);
                    return;
                }

                $applied = $appliedBySourceDocumentId->get($documentId);
                $revisionActivityId = (int) data_get($applied?->meta, 'revision_activity_id', 0);
                $request = $revisionActivityId > 0 ? $revisionRequestsById->get($revisionActivityId) : null;

                if (! $request) {
                    $request = $revisionRequests->first(function ($activity) use ($documentId): bool {
                        $meta = (array) ($activity->meta ?? []);
                        if ((int) data_get($meta, 'reference_document_id', 0) === $documentId) {
                            return true;
                        }
                        if (collect(data_get($meta, 'revision_document_ids', []))->map(fn ($id) => (int) $id)->contains($documentId)) {
                            return true;
                        }
                        return collect(data_get($meta, 'revision_items', []))
                            ->contains(fn ($item) => (int) data_get($item, 'document_id', 0) === $documentId);
                    });
                }

                $reason = '';
                if ($request) {
                    $item = collect(data_get($request->meta, 'revision_items', []))
                        ->first(fn ($entry) => (int) data_get($entry, 'document_id', 0) === $documentId);
                    $reason = trim((string) data_get($item, 'comment', ''));
                    if ($reason === '') {
                        $reason = trim((string) data_get($request->meta, 'revision_comment', ''));
                    }
                }

                $document->setAttribute('artwork_archive_status', 'Archived');
                $document->setAttribute('artwork_archive_reason_label', 'Revision reason');
                $document->setAttribute('artwork_revision_reason', $richText->plainText($reason));
                $document->setAttribute('artwork_cancelled_product_names', []);
            });
    }

    public static function activeItems(FlowJob $job): Collection
    {
        return JobDetailPresenter::products($job)
            ->filter(fn ($item) => ! ($item->is_removed ?? false))
            ->values();
    }

    public static function removedItems(FlowJob $job): Collection
    {
        return JobDetailPresenter::products($job)
            ->filter(fn ($item) => (bool) ($item->is_removed ?? false))
            ->values();
    }

    public static function itemSupplierName(object $item, FlowJob $job): string
    {
        $itemSupplier = data_get($item, 'supplier.name');
        if (filled($itemSupplier)) return (string) $itemSupplier;

        // Never lazy-load an Order-level supplier from a presenter. Supplier is
        // authoritative per item; the relation is used only when a legacy
        // caller explicitly eager-loaded it.
        if ($job->relationLoaded('supplier') && filled($job->supplier?->name)) {
            return (string) $job->supplier->name;
        }

        return 'Not linked';
    }

    public static function totalActiveUnits(FlowJob $job): int
    {
        return (int) self::activeItems($job)->sum(fn ($item) => (int) ($item->quantity ?? 0));
    }

    public static function shipmentUrgencyId(FlowJob $job): ?int
    {
        $id = collect($job->shipment_urgency_ids ?? [])->map(fn ($value) => (int) $value)->first(fn ($value) => $value > 0);
        return $id ?: null;
    }

    public static function shipmentUrgencyName(FlowJob $job, Collection $options): string
    {
        $id = self::shipmentUrgencyId($job);
        if (!$id) return 'Normal Service';
        $match = $options->first(fn ($option) => (int) data_get($option, 'id') === $id);
        return (string) (data_get($match, 'name') ?: 'Shipment priority');
    }

    public static function urgencyTone(string $name): string
    {
        $name = strtolower($name);
        if (str_contains($name, 'super')) return 'super-urgent';
        if (str_contains($name, 'urgent')) return 'urgent';
        return 'normal';
    }

    public static function currentPhaseNumber(FlowJob $job): int
    {
        return max(1, (int) ($job->phase?->sequence ?? 1));
    }

    public static function completedCount(Collection $tasks): int
    {
        return $tasks->filter(fn (Task $task) => self::isCompletedTask($task))->count();
    }
}
