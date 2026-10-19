<?php

namespace App\Support;

use App\Models\FlowJob;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BoardPresenter
{
    public static function initials(?string $name): string
    {
        return collect(preg_split('/\s+/', trim((string) $name)))
            ->filter()
            ->map(fn ($part) => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('') ?: 'FT';
    }

    public static function phaseDays(FlowJob $job): int
    {
        $entered = $job->getAttribute('phase_entered_at');
        if (is_string($entered) && $entered !== '') $entered = \Illuminate\Support\Carbon::parse($entered);
        if (!$entered && $job->relationLoaded('phaseHistories')) {
            $entered = $job->phaseHistories
                ->first(fn ($row) => (int) $row->workflow_phase_id === (int) $job->workflow_phase_id && $row->entered_at)?->entered_at;
        }
        $entered ??= $job->updated_at ?? $job->created_at;

        $clock = app(\App\Services\WorkspaceSettingsService::class);
        $enteredLocal = $entered?->copy()->timezone($clock->displayTimezone())->startOfDay();

        return max(1, (int) $enteredLocal?->diffInDays($clock->localToday()));
    }

    public static function currentTasks(FlowJob $job): Collection
    {
        return $job->tasks
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->sortBy(fn ($task) => [$task->completed_at ? 1 : 0, $task->due_date?->format('Y-m-d') ?? '9999-12-31', $task->id])
            ->values();
    }

    public static function openTasks(FlowJob $job): Collection
    {
        return self::currentTasks($job)->filter(fn ($task) => !$task->completed_at && $task->status !== 'Completed')->values();
    }

    public static function nextTask(FlowJob $job): ?Task
    {
        return self::openTasks($job)->first() ?: $job->tasks->first(fn ($task) => !$task->completed_at && $task->status !== 'Completed');
    }

    public static function dueSoonCount(FlowJob $job): int
    {
        return self::openTasks($job)->filter(fn ($task) => $task->due_date && $task->due_date->betweenIncluded(app(\App\Services\WorkspaceSettingsService::class)->localToday(), app(\App\Services\WorkspaceSettingsService::class)->localToday()->addDays(3)))->count();
    }

    public static function blockedCount(FlowJob $job): int
    {
        return self::openTasks($job)->where('status', 'Blocked')->count();
    }

    public static function team(FlowJob $job): Collection
    {
        $members = $job->members->pluck('user')->filter();
        $taskPeople = self::currentTasks($job)->pluck('assignee')->filter();
        $base = collect([$job->owner, $job->coordinator])->filter();

        return $base->concat($members)->concat($taskPeople)->unique('id')->values();
    }

    public static function productCount(FlowJob $job): int
    {
        return max(1, $job->items->count());
    }

    public static function totalUnits(FlowJob $job): int
    {
        $items = (int) $job->items->sum('quantity');
        return $items > 0 ? $items : (int) $job->quantity;
    }

    public static function commercialLabel(FlowJob $job): string
    {
        if ((float) $job->commercial_value <= 0) {
            return 'Quotation pending';
        }

        return $job->currency.' '.number_format((float) $job->commercial_value, 0);
    }

    public static function lastUpdatedText(FlowJob|Task $model): string
    {
        return $model->updated_at?->shortAbsoluteDiffForHumans() ?? 'recently';
    }

    public static function updatedBy(FlowJob $job): string
    {
        return ($job->relationLoaded('latestActivity') ? $job->latestActivity?->user?->name : null)
            ?? ($job->relationLoaded('activities') ? $job->activities->first()?->user?->name : null)
            ?? $job->coordinator?->name
            ?? $job->owner?->name
            ?? 'FlowTrack';
    }

    public static function overdueDays(Task $task): int
    {
        if (!$task->due_date || !\App\Support\UserLocalTime::isDatePast($task->due_date) || $task->completed_at) {
            return 0;
        }

        return max(1, (int) $task->due_date->copy()->startOfDay()->diffInDays(app(\App\Services\WorkspaceSettingsService::class)->localToday()));
    }

    public static function waitingLabel(Task $task): ?string
    {
        if (!Str::startsWith($task->status, 'Waiting for ')) {
            return null;
        }

        $party = Str::after($task->status, 'Waiting for ');
        $reason = trim((string) $task->attention_reason);

        if ($reason !== '') {
            return $reason;
        }

        $title = Str::lower($task->title);
        if ($party === 'Supplier' && Str::contains($title, ['cost', 'quotation', 'price'])) return 'Supplier costing';
        if ($party === 'Supplier') return 'Supplier response';
        if ($party === 'Client' && Str::contains($title, ['approve', 'artwork'])) return 'Client approval';
        if ($party === 'Client') return 'Client response';
        if ($party === 'Internal Approval') return 'Internal approval';

        return $party;
    }
}
