<?php

namespace App\Services\Orders;

use App\Models\FlowJob;
use App\Models\OrderHold;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\DashboardService;
use App\Services\JobService;
use App\Services\MentionService;
use App\Services\NotificationService;
use App\Services\ReportService;
use App\Services\ShellDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderHoldService
{
    public const BLOCKED_ACTIVITY_MESSAGE = 'This Order is on hold. Release the hold before performing any activity.';

    public function place(FlowJob $job, User $actor, string $holdFrom, ?string $reason = null): OrderHold
    {
        $this->assertCanManageHold($job, $actor);

        $holdFrom = strtolower(trim($holdFrom));
        if (! array_key_exists($holdFrom, OrderHold::HOLD_FROM_OPTIONS)) {
            throw ValidationException::withMessages([
                'orderHoldFrom' => 'Select where the hold is coming from.',
            ]);
        }

        // The hold reason is intentionally optional. Keep the stored value plain
        // text so the existing @mention tokenizer can safely render it anywhere
        // the hold is shown (planning card, lock dialog, and activity history).
        $reason = trim(strip_tags((string) $reason));
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'orderHoldReason' => 'The hold reason may not be greater than 500 characters.',
            ]);
        }

        $mentionIds = $reason !== ''
            ? app(MentionService::class)->userIdsFromText($reason)
            : [];

        $hold = DB::transaction(function () use ($job, $actor, $holdFrom, $reason, $mentionIds): OrderHold {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManageHold($lockedJob, $actor);

            abort_if(
                OrderHold::query()->where('flow_job_id', $lockedJob->id)->whereNull('ended_at')->exists(),
                422,
                'This Order is already on hold.'
            );

            $startedAt = now();
            $sourceName = $this->resolveSourceName($lockedJob, $actor, $holdFrom);
            $hold = OrderHold::query()->create([
                'flow_job_id' => $lockedJob->id,
                'hold_from' => $holdFrom,
                'source_name' => $sourceName,
                'reason' => $reason,
                'held_by' => $actor->id,
                'started_at' => $startedAt,
            ]);

            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.hold_started',
                'description' => 'Order placed on hold',
                'meta' => [
                    'order_hold_id' => (int) $hold->id,
                    'hold_from' => $holdFrom,
                    'hold_from_label' => OrderHold::labelFor($holdFrom),
                    'source_name' => $sourceName,
                    'reason' => $reason,
                    'mention_user_ids' => $mentionIds,
                    'held_by' => (int) $actor->id,
                    'held_by_name' => (string) $actor->name,
                    'started_at' => $startedAt->toISOString(),
                ],
            ]);

            return $hold->load('holder:id,name,profile_image_path');
        }, 3);

        if ($mentionIds !== []) {
            app(NotificationService::class)->notifyMentionedUsers(
                $mentionIds,
                $actor->name.' mentioned you in an Order hold · '.$job->displayOrderNumber(),
                $reason,
                $job,
                null,
                $actor,
            );
        }

        // Hold state is part of the materialized Order Details summary. This is
        // a derived read-model update only; the OrderHold row above remains the
        // authoritative source and all existing task guards stay unchanged.
        app(OrderWorkflowSummaryService::class)->updateHoldState((int) $job->id, true);
        $this->forgetOperationalCaches($actor);

        return $hold;
    }

    public function release(FlowJob $job, User $actor): OrderHold
    {
        $this->assertCanManageHold($job, $actor);

        $hold = DB::transaction(function () use ($job, $actor): OrderHold {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManageHold($lockedJob, $actor);

            $hold = OrderHold::query()
                ->where('flow_job_id', $lockedJob->id)
                ->whereNull('ended_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            abort_unless($hold, 422, 'This Order is not currently on hold.');

            $endedAt = now();
            $hold->update([
                'ended_at' => $endedAt,
                'released_by' => $actor->id,
            ]);
            $hold->loadMissing('holder:id,name,profile_image_path');

            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.hold_released',
                'description' => 'Order unheld',
                'meta' => [
                    'order_hold_id' => (int) $hold->id,
                    'hold_from' => (string) $hold->hold_from,
                    'hold_from_label' => $hold->holdFromLabel(),
                    'source_name' => (string) ($hold->source_name ?: $this->resolveSourceName($lockedJob, $hold->holder ?: $actor, (string) $hold->hold_from)),
                    'reason' => (string) $hold->reason,
                    'held_by' => $hold->held_by ? (int) $hold->held_by : null,
                    'held_by_name' => (string) ($hold->holder?->name ?: 'Unknown user'),
                    'started_at' => $hold->started_at?->toISOString(),
                    'ended_at' => $endedAt->toISOString(),
                    'duration' => OrderHold::durationLabel($hold->started_at, $endedAt),
                    'released_by' => (int) $actor->id,
                    'released_by_name' => (string) $actor->name,
                ],
            ]);

            return $hold->load('releaser:id,name,profile_image_path');
        }, 3);

        app(OrderWorkflowSummaryService::class)->updateHoldState((int) $job->id, false);
        $this->forgetOperationalCaches($actor);

        return $hold;
    }

    public function activeHold(int|FlowJob $job): ?OrderHold
    {
        $jobId = $job instanceof FlowJob ? (int) $job->id : (int) $job;

        return OrderHold::query()
            ->where('flow_job_id', $jobId)
            ->whereNull('ended_at')
            ->with('holder:id,name,profile_image_path')
            ->latest('id')
            ->first();
    }

    public function isHeld(int|FlowJob $job): bool
    {
        $jobId = $job instanceof FlowJob ? (int) $job->id : (int) $job;

        return OrderHold::query()
            ->where('flow_job_id', $jobId)
            ->whereNull('ended_at')
            ->exists();
    }

    public function assertNotHeld(int|FlowJob $job): void
    {
        abort_if($this->isHeld($job), 422, self::BLOCKED_ACTIVITY_MESSAGE);
    }

    private function resolveSourceName(FlowJob $job, User $actor, string $holdFrom): string
    {
        if ($holdFrom === OrderHold::FROM_CLIENT) {
            $job->loadMissing('client:id,name,contact_name');

            return trim((string) ($job->client?->contact_name ?: $job->client?->name ?: 'Client'));
        }

        return trim((string) $actor->name) ?: OrderHold::labelFor($holdFrom);
    }

    private function assertCanManageHold(FlowJob $job, User $actor): void
    {
        abort_unless(app(AccessControlService::class)->canEditJob($actor, $job), 403);
        abort_if(
            $job->completed_at || in_array((string) $job->status, JobService::INACTIVE_STATUSES, true),
            422,
            'A completed or inactive Order cannot be placed on hold.'
        );
    }

    private function forgetOperationalCaches(User $actor): void
    {
        app(DashboardService::class)->forget($actor);
        app(ReportService::class)->forget($actor->id);
        app(ShellDataService::class)->forget($actor->id);
    }
}
