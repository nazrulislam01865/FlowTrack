<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Resolves the semantic artwork state used to tint an Order-list row.
 *
 * Artwork has branching decisions that are not represented by one permanent
 * task status. In particular, a revision resets the upload task to Ready and
 * stores the revision request as an activity. Keeping the resolver here lets
 * the list reflect the real artwork state without changing workflow data.
 */
final class OrderArtworkListState
{
    public const ARTWORK_CONFIRMED = 'artwork_confirmed';
    public const CLIENT_DECISION = 'client_decision';
    public const CLIENT_APPROVED = 'client_approved';
    public const REVISION_REQUIRED = 'revision_required';

    /** @return array{key:string,label:string,color:string}|null */
    public static function resolve(Collection $phaseTasks, ?Task $nextTask, ?Activity $latestRevision): ?array
    {
        $upload = self::taskByKey($phaseTasks, 'ART_PREPARE_UPLOAD');
        $review = self::taskByKey($phaseTasks, 'ART_INTERNAL_REVIEW');
        $client = self::taskByKey($phaseTasks, 'ART_CLIENT_ERP_DECISION');
        $sample = self::taskByKey($phaseTasks, 'ART_SAMPLE_APPROVAL');

        if (self::hasOutstandingRevision($upload, $latestRevision)) {
            return self::state(self::REVISION_REQUIRED);
        }

        $clientStatus = strtolower(trim((string) ($client?->status ?? '')));
        if (str_contains($clientStatus, 'waiting for sample approval')
            || ($sample && ! OrderDetailPresenter::isCompletedTask($sample)
                && ! OrderDetailPresenter::isSkippedTask($sample)
                && OrderDetailPresenter::isConditionalTaskActivated($sample))) {
            return self::state(self::CLIENT_APPROVED);
        }

        if (str_contains($clientStatus, 'waiting for client') || str_contains($clientStatus, 'client approval')) {
            return self::state(self::CLIENT_DECISION);
        }

        $nextKey = self::automationKey($nextTask);
        if ($nextKey === 'ART_INTERNAL_REVIEW' || ($review && OrderDetailPresenter::isCompletedTask($review))) {
            return self::state(self::ARTWORK_CONFIRMED);
        }

        return null;
    }

    /** @return array{key:string,label:string,color:string} */
    public static function state(string $key): array
    {
        // CHANGE 2026-08-24: match the Order-list prototype exactly.
        // Internal review = blue, revision = red, client approval = purple.
        return match ($key) {
            self::ARTWORK_CONFIRMED => ['key' => $key, 'label' => 'Internal review', 'color' => '#2D8CF0'],
            self::CLIENT_DECISION => ['key' => $key, 'label' => 'Client approval', 'color' => '#8B5CF6'],
            self::CLIENT_APPROVED => ['key' => $key, 'label' => 'Client approval', 'color' => '#8B5CF6'],
            self::REVISION_REQUIRED => ['key' => $key, 'label' => 'Revision required', 'color' => '#EF476F'],
            default => ['key' => $key, 'label' => 'Internal review', 'color' => '#2D8CF0'],
        };
    }

    private static function hasOutstandingRevision(?Task $upload, ?Activity $latestRevision): bool
    {
        if (! $latestRevision) return false;

        $latestArtwork = $upload?->relationLoaded('documents')
            ? $upload->documents->sortByDesc('created_at')->first()
            : null;

        // A revision request remains active until a newer artwork file is
        // uploaded. This avoids changing task statuses simply for list colors.
        if (! $latestArtwork?->created_at) return true;
        if (! $latestRevision->created_at) return false;

        return $latestRevision->created_at->greaterThan($latestArtwork->created_at);
    }

    private static function taskByKey(Collection $tasks, string $key): ?Task
    {
        return $tasks->first(fn (Task $task) => self::automationKey($task) === $key);
    }

    private static function automationKey(?Task $task): ?string
    {
        if (! $task) return null;

        $key = trim((string) ($task->setupTemplate?->automation_key ?? ''));
        if ($key !== '') return $key;

        return match (strtolower(trim((string) $task->title))) {
            'prepare & upload artwork', 'prepare and upload artwork' => 'ART_PREPARE_UPLOAD',
            'internal artwork review' => 'ART_INTERNAL_REVIEW',
            'client erp / approval' => 'ART_CLIENT_ERP_DECISION',
            'sample approval (when required)' => 'ART_SAMPLE_APPROVAL',
            default => null,
        };
    }
}
