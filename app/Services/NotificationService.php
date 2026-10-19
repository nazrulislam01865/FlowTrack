<?php

namespace App\Services;

use App\Jobs\DeliverRealtimeNotification;
use App\Models\FlowJob;
use App\Models\FlowNotification;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function visibleQuery(User $user): Builder
    {
        $access = app(AccessControlService::class);
        $query = FlowNotification::query()->where('user_id', $user->id);
        if (!$access->can($user, 'notifications', 'view')) return $query->whereRaw('1 = 0');

        // Administrator copies are only visible while the account is actually an
        // Admin/Super Admin. This keeps the rule correct even if a user's role is
        // changed later: normal users always see only mentions addressed to them.
        if (! app(AccessControlService::class)->isAdministrator($user)) {
            $query->where('type', '!=', 'mention_admin');
        }

        return $this->constrainToAuthorizedContexts($this->constrainToLiveRecordContexts($query), $user);
    }

    /**
     * Notifications remain useful audit data in the database, but the UI must
     * never offer a link to a soft-deleted Order/Task/Inquiry. Task notifications
     * also require their parent Order/Inquiry to still exist; deleting a parent
     * does not soft-delete every child row automatically.
     */
    private function constrainToLiveRecordContexts(Builder $query): Builder
    {
        return $query->where(function (Builder $live): void {
            $live
                ->where(function (Builder $generic): void {
                    $generic
                        ->whereNull('flow_job_id')
                        ->whereNull('flow_task_id')
                        ->whereNull('inquiry_id')
                        ->whereNull('inquiry_task_id');
                })
                ->orWhere(function (Builder $task): void {
                    $task
                        ->whereNotNull('flow_task_id')
                        ->whereHas('task.job');
                })
                ->orWhere(function (Builder $inquiryTask): void {
                    $inquiryTask
                        ->whereNull('flow_task_id')
                        ->whereNotNull('inquiry_task_id')
                        ->whereHas('inquiryTask.inquiry');
                })
                ->orWhere(function (Builder $job): void {
                    $job
                        ->whereNull('flow_task_id')
                        ->whereNull('inquiry_task_id')
                        ->whereNull('inquiry_id')
                        ->whereNotNull('flow_job_id')
                        ->whereHas('job');
                })
                ->orWhere(function (Builder $inquiry): void {
                    $inquiry
                        ->whereNull('flow_task_id')
                        ->whereNull('inquiry_task_id')
                        ->whereNull('flow_job_id')
                        ->whereNotNull('inquiry_id')
                        ->whereHas('inquiry');
                });
        });
    }

    private function constrainToAuthorizedContexts(Builder $query, User $user): Builder
    {
        $access = app(AccessControlService::class);

        return $query->where(function (Builder $allowed) use ($user, $access): void {
            $allowed->where(function (Builder $generic): void {
                $generic->whereNull('flow_job_id')
                    ->whereNull('flow_task_id')
                    ->whereNull('inquiry_id')
                    ->whereNull('inquiry_task_id');
            });

            if ($access->can($user, 'tasks', 'view')) {
                $allowed->orWhere(function (Builder $task) use ($user, $access): void {
                    $task->whereNotNull('flow_task_id')
                        ->whereIn('flow_task_id', $access->applyTaskScope(Task::query(), $user)->select('tasks.id'));
                });
            }

            if ($access->can($user, 'tasks', 'view') && $access->can($user, 'inquiries', 'view')) {
                $allowed->orWhere(function (Builder $task) use ($user, $access): void {
                    $task->whereNull('flow_task_id')
                        ->whereNotNull('inquiry_task_id')
                        ->whereIn('inquiry_task_id', $access->applyInquiryTaskScope(InquiryTask::query(), $user)->select('inquiry_tasks.id'));
                });
            }

            if ($access->can($user, 'jobs', 'view')) {
                $allowed->orWhere(function (Builder $job) use ($user): void {
                    $job->whereNull('flow_task_id')
                        ->whereNull('inquiry_task_id')
                        ->whereNull('inquiry_id')
                        ->whereNotNull('flow_job_id')
                        ->whereIn('flow_job_id', app(JobService::class)->visibleQuery($user)->select('flow_jobs.id'));
                });
            }

            if ($access->can($user, 'inquiries', 'view')) {
                $allowed->orWhere(function (Builder $inquiry) use ($user): void {
                    $inquiry->whereNull('flow_task_id')
                        ->whereNull('inquiry_task_id')
                        ->whereNull('flow_job_id')
                        ->whereNotNull('inquiry_id')
                        ->whereIn('inquiry_id', app(InquiryService::class)->visibleQuery($user)->select('inquiries.id'));
                });
            }
        });
    }

    public function list(User $user)
    {
        return $this->visibleQuery($user)
            ->with(['job','task','inquiry','inquiryTask'])
            ->latest()
            ->get();
    }

    public function paginate(User $user, int $perPage = 30)
    {
        return $this->visibleQuery($user)
            ->with(['job','task','inquiry','inquiryTask'])
            ->latest()
            ->paginate($perPage, ['*'], 'notificationsPage');
    }

    public function latest(User $user): ?FlowNotification
    {
        return $this->visibleQuery($user)->latest('created_at')->latest('id')->first();
    }

    public function unreadCount(User $user): int
    {
        return $this->visibleQuery($user)->whereNull('read_at')->count();
    }

    public function markAllRead(User $user): void
    {
        $this->visibleQuery($user)->whereNull('read_at')->update(['read_at' => now()]);
        app(DashboardService::class)->forgetMentions($user);
        app(ShellDataService::class)->forget($user->id);
        $this->broadcastRealtimeState($user);
    }

    /**
     * Mark one currently-visible notification as read and synchronize the unread
     * badge/notification surfaces in the user's other connected tabs.
     */
    public function markRead(User $user, FlowNotification $notification): int
    {
        $notification = $this->visibleQuery($user)->whereKey($notification->id)->firstOrFail();

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
            app(DashboardService::class)->forgetMentions($user);
            app(ShellDataService::class)->forget($user->id);
        }

        $unreadCount = $this->unreadCount($user);
        $this->broadcastRealtimeState($user, $unreadCount);

        return $unreadCount;
    }

    /**
     * Reverb state event used for read/unread synchronization. FlowNotification
     * intentionally is not a workspace-observed model because private notification
     * state belongs only to its recipient, not every user in the workspace.
     */
    public function broadcastRealtimeState(User $recipient, ?int $unreadCount = null): void
    {
        if (! $recipient->is_active || ! app(ReverbChannelService::class)->enabled()) return;

        $this->queueUserRealtimeEvent(
            $recipient,
            'flowtrack.notification-state',
            ['unread_count' => $unreadCount ?? $this->unreadCount($recipient)],
        );
    }

    public function notifyUser(
        User $recipient,
        string $title,
        string $message,
        string $type = 'info',
        ?FlowJob $job = null,
        ?Task $task = null,
        ?User $actor = null,
        bool $invalidateRecipientCaches = true,
    ): ?FlowNotification {
        if (!$recipient->is_active) return null;

        $access = app(AccessControlService::class);
        if ($task) {
            $visible = $access->applyTaskScope(Task::query()->whereKey($task->id), $recipient)->exists();
            if (!$visible) return null;

            // A Task is not actionable after its parent Order has been deleted,
            // even though the child Task row itself may still be soft-delete-live.
            $liveParentJob = $task->relationLoaded('job')
                ? $task->getRelation('job')
                : $task->job()->first();
            if (! $liveParentJob) return null;
            $job = $liveParentJob;
        } elseif ($job) {
            $visible = $access->applyJobScope(FlowJob::query()->whereKey($job->id), $recipient)->exists();
            if (!$visible) return null;
        }

        $notification = FlowNotification::create([
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'flow_job_id' => $job?->id,
            'flow_task_id' => $task?->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ]);

        if ($invalidateRecipientCaches) {
            $this->forgetRecipientCaches($recipient);
        }
        $this->deliverRealtime($recipient, $notification, $job, $task);

        return $notification;
    }

    public function notifyJobParticipants(
        FlowJob $job,
        string $title,
        string $message,
        string $type = 'info',
        ?User $actor = null,
        array $extraUserIds = [],
        array $excludeUserIds = [],
    ): void {
        $excluded = array_map('intval', $excludeUserIds);
        $assignedUserId = (int) (($job->owner_id ?? null) ?: ($job->coordinator_id ?? 0));
        $ids = collect($assignedUserId > 0 ? [$assignedUserId] : [])
            ->merge($extraUserIds)
            ->merge($this->administratorIds())
            ->filter()
            ->reject(fn ($id) => in_array((int) $id, $excluded, true))
            ->unique()->values()->all();

        $this->fanOutAfterCommit($ids, $title, $message, $type, $job->id, null, $actor?->id);
    }

    public function notifyOrderAttentionUsers(
        array $recipientIds,
        string $title,
        string $message,
        FlowJob $job,
        ?User $actor = null,
    ): void {
        $ids = collect($recipientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) return;

        $this->fanOutAfterCommit(
            $ids,
            $title,
            $message,
            'risk',
            (int) $job->id,
            null,
            $actor?->id ? (int) $actor->id : null,
        );
    }

    public function notifyTaskParticipants(
        Task $task,
        string $title,
        string $message,
        string $type = 'info',
        ?User $actor = null,
        array $extraUserIds = [],
        array $excludeUserIds = [],
        bool $invalidateRecipientCaches = true,
    ): void {
        $excluded = array_map('intval', $excludeUserIds);
        $task->loadMissing('job.members');
        $job = $task->job;
        $ids = collect([$task->assignee_id, $job?->owner_id, $job?->coordinator_id])
            ->merge($job?->members?->pluck('user_id') ?? collect())
            ->merge($extraUserIds)
            ->merge($actor ? [$actor->id] : [])
            ->filter()
            ->reject(fn ($id) => in_array((int) $id, $excluded, true))
            ->unique()->values();

        if ($type === 'risk') $ids = $ids->merge($this->administratorIds())->unique()->values();

        $this->fanOutAfterCommit(
            $ids->all(),
            $title,
            $message,
            $type,
            $job?->id,
            $task->id,
            $actor?->id,
            $invalidateRecipientCaches,
        );
    }

    public function backfillAdministratorMentions(User $administrator): void
    {
        if (! $administrator->is_active || ! app(AccessControlService::class)->isAdministrator($administrator)) {
            return;
        }

        $seen = [];
        $now = now();

        FlowNotification::query()
            ->where('type', 'mention')
            ->where(function (Builder $query): void {
                $query->whereNotNull('flow_job_id')
                    ->orWhereNotNull('flow_task_id')
                    ->orWhereNotNull('inquiry_id')
                    ->orWhereNotNull('inquiry_task_id');
            })
            ->chunkById(500, function (Collection $notifications) use ($administrator, &$seen, $now): void {
                foreach ($notifications as $source) {
                    $signature = hash('sha256', implode('|', [
                        (string) ($source->flow_job_id ?? ''),
                        (string) ($source->flow_task_id ?? ''),
                        (string) ($source->inquiry_id ?? ''),
                        (string) ($source->inquiry_task_id ?? ''),
                        (string) $source->title,
                        (string) $source->message,
                        (string) $source->created_at,
                    ]));

                    if (isset($seen[$signature])) continue;
                    $seen[$signature] = true;

                    $alreadyHasEvent = FlowNotification::query()
                        ->where('user_id', $administrator->id)
                        ->whereIn('type', ['mention', 'mention_admin'])
                        ->where('flow_job_id', $source->flow_job_id)
                        ->where('flow_task_id', $source->flow_task_id)
                        ->where('inquiry_id', $source->inquiry_id)
                        ->where('inquiry_task_id', $source->inquiry_task_id)
                        ->where('message', $source->message)
                        ->where('created_at', $source->created_at)
                        ->exists();

                    if ($alreadyHasEvent) continue;

                    FlowNotification::query()->create([
                        'user_id' => $administrator->id,
                        'actor_id' => $source->actor_id,
                        'flow_job_id' => $source->flow_job_id,
                        'flow_task_id' => $source->flow_task_id,
                        'inquiry_id' => $source->inquiry_id,
                        'inquiry_task_id' => $source->inquiry_task_id,
                        'type' => 'mention_admin',
                        'title' => $this->administratorMentionTitle((string) $source->title),
                        'message' => $source->message,
                        'read_at' => $now,
                        'created_at' => $source->created_at,
                        'updated_at' => $now,
                    ]);
                }
            });

        $this->forgetRecipientCaches($administrator);
    }

    public function notifyMentionedUsers(
        array $recipientIds,
        string $title,
        string $message,
        ?FlowJob $job = null,
        ?Task $task = null,
        ?User $actor = null,
    ): void {
        $directIds = collect($recipientIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($directIds->isEmpty()) return;

        // Every tagged comment is also copied to Admin/Super Admin so their
        // dashboard is a workspace-wide mention feed. Directly tagged admins
        // receive only the normal copy, never a duplicate administrator copy.
        $ids = $directIds->merge($this->administratorIds())->unique()->values();
        $directLookup = array_fill_keys($directIds->all(), true);
        $jobId = $job?->id;
        $taskId = $task?->id;
        $actorId = $actor?->id;

        $this->runAfterCommit(function () use ($ids, $directLookup, $title, $message, $jobId, $taskId, $actorId): void {
            $job = $jobId ? FlowJob::withTrashed()->find($jobId) : null;
            $task = $taskId ? Task::withTrashed()->find($taskId) : null;
            $actor = $actorId ? User::find($actorId) : null;

            User::query()
                ->whereIn('id', $ids->all())
                ->where('is_active', true)
                ->get()
                ->each(function (User $recipient) use ($directLookup, $title, $message, $job, $task, $actor): void {
                    $direct = isset($directLookup[(int) $recipient->id]);
                    $this->createMentionNotification(
                        $recipient,
                        $direct ? $title : $this->administratorMentionTitle($title),
                        $message,
                        $job,
                        $task,
                        $actor,
                        $direct ? 'mention' : 'mention_admin',
                    );
                });
        });
    }

    /**
     * Create an Inquiry-scoped notification through the same queued Reverb path
     * used by Order/Task notifications. Keeping this here prevents Inquiry
     * services from writing FlowNotification rows that never reach the browser.
     */
    public function notifyInquiryUser(
        User $recipient,
        Inquiry $inquiry,
        ?InquiryTask $inquiryTask,
        string $title,
        string $message,
        string $type = 'info',
        ?User $actor = null,
    ): void {
        if (! $recipient->is_active) return;

        $recipientId = (int) $recipient->id;
        $inquiryId = (int) $inquiry->id;
        $inquiryTaskId = $inquiryTask?->id ? (int) $inquiryTask->id : null;
        $actorId = $actor?->id ? (int) $actor->id : null;

        $this->runAfterCommit(function () use ($recipientId, $inquiryId, $inquiryTaskId, $title, $message, $type, $actorId): void {
            $recipient = User::query()->where('is_active', true)->find($recipientId);
            $inquiry = Inquiry::withTrashed()->find($inquiryId);
            if (! $recipient || ! $inquiry || $inquiry->trashed()) return;

            $inquiryTask = $inquiryTaskId ? InquiryTask::withTrashed()->find($inquiryTaskId) : null;
            if ($inquiryTaskId && (! $inquiryTask || $inquiryTask->trashed() || (int) $inquiryTask->inquiry_id !== $inquiryId)) return;

            $visible = app(InquiryService::class)->visibleQuery($recipient)->whereKey($inquiryId)->exists();
            if (! $visible) return;

            $notification = FlowNotification::create([
                'user_id' => $recipient->id,
                'actor_id' => $actorId,
                'flow_job_id' => null,
                'flow_task_id' => null,
                'inquiry_id' => $inquiry->id,
                'inquiry_task_id' => $inquiryTask?->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
            ]);

            $this->forgetRecipientCaches($recipient);
            $this->deliverRealtime($recipient, $notification, null, null, $inquiry, $inquiryTask);
        });
    }

    public function notifyInquiryMentionedUsers(
        array $recipientIds,
        string $title,
        string $message,
        Inquiry $inquiry,
        ?InquiryTask $inquiryTask = null,
        ?User $actor = null,
    ): void {
        $directIds = collect($recipientIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($directIds->isEmpty()) return;

        $ids = $directIds->merge($this->administratorIds())->unique()->values();
        $directLookup = array_fill_keys($directIds->all(), true);
        $inquiryId = (int) $inquiry->id;
        $inquiryTaskId = $inquiryTask?->id ? (int) $inquiryTask->id : null;
        $actorId = $actor?->id ? (int) $actor->id : null;

        $this->runAfterCommit(function () use ($ids, $directLookup, $title, $message, $inquiryId, $inquiryTaskId, $actorId): void {
            $inquiry = Inquiry::withTrashed()->find($inquiryId);
            if (!$inquiry || $inquiry->trashed()) return;

            $inquiryTask = $inquiryTaskId ? InquiryTask::withTrashed()->find($inquiryTaskId) : null;
            if ($inquiryTaskId && (! $inquiryTask || $inquiryTask->trashed())) return;

            User::query()
                ->whereIn('id', $ids->all())
                ->where('is_active', true)
                ->get()
                ->each(function (User $recipient) use ($directLookup, $title, $message, $inquiry, $inquiryTask, $actorId): void {
                    $visible = app(InquiryService::class)
                        ->visibleQuery($recipient)
                        ->whereKey($inquiry->id)
                        ->exists();
                    if (!$visible) return;

                    $direct = isset($directLookup[(int) $recipient->id]);
                    $notification = FlowNotification::create([
                        'user_id' => $recipient->id,
                        'actor_id' => $actorId,
                        'flow_job_id' => null,
                        'flow_task_id' => null,
                        'inquiry_id' => $inquiry->id,
                        'inquiry_task_id' => $inquiryTask?->id,
                        'type' => $direct ? 'mention' : 'mention_admin',
                        'title' => $direct ? $title : $this->administratorMentionTitle($title),
                        'message' => $message,
                    ]);

                    $this->forgetRecipientCaches($recipient);
                    $this->deliverRealtime($recipient, $notification, null, null, $inquiry, $inquiryTask);
                });
        });
    }

    public function notifyInquiryAttentionUsers(
        array $recipientIds,
        string $title,
        string $message,
        Inquiry $inquiry,
        ?InquiryTask $inquiryTask = null,
        ?User $actor = null,
    ): void {
        $ids = collect($recipientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return;

        $inquiryId = (int) $inquiry->id;
        $inquiryTaskId = $inquiryTask?->id ? (int) $inquiryTask->id : null;
        $actorId = $actor?->id ? (int) $actor->id : null;

        $this->runAfterCommit(function () use ($ids, $title, $message, $inquiryId, $inquiryTaskId, $actorId): void {
            $inquiry = Inquiry::withTrashed()->find($inquiryId);
            if (! $inquiry || $inquiry->trashed()) return;
            $inquiryTask = $inquiryTaskId ? InquiryTask::withTrashed()->find($inquiryTaskId) : null;
            if ($inquiryTaskId && (! $inquiryTask || $inquiryTask->trashed())) return;

            User::query()->whereIn('id', $ids->all())->where('is_active', true)->get()->each(function (User $recipient) use ($title, $message, $inquiry, $inquiryTask, $actorId): void {
                $visible = app(InquiryService::class)->visibleQuery($recipient)->whereKey($inquiry->id)->exists();
                if (! $visible) return;

                $notification = FlowNotification::create([
                    'user_id' => $recipient->id,
                    'actor_id' => $actorId,
                    'flow_job_id' => null,
                    'flow_task_id' => null,
                    'inquiry_id' => $inquiry->id,
                    'inquiry_task_id' => $inquiryTask?->id,
                    'type' => 'risk',
                    'title' => $title,
                    'message' => $message,
                ]);

                $this->forgetRecipientCaches($recipient);
                $this->deliverRealtime($recipient, $notification, null, null, $inquiry, $inquiryTask);
            });
        });
    }

    public function notifyTaskAssigned(Task $task, ?User $actor = null): void
    {
        if (!$task->assignee_id) return;
        $task->loadMissing(['job','phase','assignee']);
        if (!$task->assignee) return;

        $this->fanOutAfterCommit(
            [$task->assignee->id],
            'Task assigned: '.$task->title,
            ($task->job?->displayOrderNumber() ? $task->job->displayOrderNumber().' · ' : '').($task->phase?->name ?: 'Task').' · '.($task->due_date?->format('M j, Y') ?: 'No due date'),
            'assignment',
            $task->job?->id,
            $task->id,
            $actor?->id,
        );
    }

    public function notifyJobAssigned(
        FlowJob $job,
        ?User $actor = null,
        array $extraUserIds = [],
        array $excludeUserIds = [],
    ): void {
        $this->notifyJobParticipants(
            $job,
            'Job assigned: '.$job->title,
            $job->displayOrderNumber().' · '.($job->client?->name ?: 'Client').' · '.($job->phase?->name ?: 'Workflow started'),
            'assignment',
            $actor,
            $extraUserIds,
            $excludeUserIds,
        );
    }

    public function urlFor(FlowNotification $notification): string
    {
        // Always open through the notification resolver. It verifies current
        // access, repairs stale job/task pairings, marks the notification read,
        // and deep-links comment notifications to the exact comment when possible.
        return route('notifications.open', ['notification' => $notification->id]);
    }

    private function createMentionNotification(
        User $recipient,
        string $title,
        string $message,
        ?FlowJob $job,
        ?Task $task,
        ?User $actor,
        string $type = 'mention',
    ): ?FlowNotification {
        if (!$recipient->is_active) return null;

        $access = app(AccessControlService::class);
        $notificationJob = $job && !$job->trashed() ? $job : null;
        $notificationTask = $task && !$task->trashed() ? $task : null;

        // A mention that originated from a record must never be downgraded to a
        // contextless notification if that record was deleted or became invisible
        // before the post-commit fan-out runs.
        if ($task) {
            if (! $notificationTask) return null;

            $canOpenTask = $access->applyTaskScope(Task::query()->whereKey($notificationTask->id), $recipient)->exists();
            if (! $canOpenTask) return null;

            $notificationJob = $notificationTask->job()->first();
            if (! $notificationJob) return null;
        } elseif ($job) {
            if (! $notificationJob) return null;

            $canOpenJob = $access->applyJobScope(FlowJob::query()->whereKey($notificationJob->id), $recipient)->exists();
            if (! $canOpenJob) return null;
        }

        $notification = FlowNotification::create([
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'flow_job_id' => $notificationJob?->id,
            'flow_task_id' => $notificationTask?->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
        ]);

        $this->forgetRecipientCaches($recipient);
        $this->deliverRealtime($recipient, $notification, $notificationJob, $notificationTask);

        return $notification;
    }

    private function fanOutAfterCommit(
        array $recipientIds,
        string $title,
        string $message,
        string $type,
        ?int $jobId,
        ?int $taskId,
        ?int $actorId,
        bool $invalidateRecipientCaches = true,
    ): void {
        $ids = collect($recipientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) return;

        $this->runAfterCommit(function () use ($ids, $title, $message, $type, $jobId, $taskId, $actorId, $invalidateRecipientCaches): void {
            $job = $jobId ? FlowJob::withTrashed()->find($jobId) : null;
            $task = $taskId ? Task::withTrashed()->find($taskId) : null;
            $actor = $actorId ? User::find($actorId) : null;
            $visibleJob = $job && !$job->trashed() ? $job : null;
            $visibleTask = $task && !$task->trashed() ? $task : null;

            // notifyUser() checks the live parent before creating a Task
            // notification. Reuse the parent already loaded once for this fanout
            // instead of re-querying flow_jobs separately for every recipient.
            if ($visibleTask && $visibleJob) {
                $visibleTask->setRelation('job', $visibleJob);
            }

            User::query()
                ->with('roles')
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->get()
                ->each(fn (User $recipient) => $this->notifyUser(
                    $recipient,
                    $title,
                    $message,
                    $type,
                    $visibleJob,
                    $visibleTask,
                    $actor,
                    $invalidateRecipientCaches,
                ));
        });
    }

    private function legacyActorFromMentionTitle(string $title): ?User
    {
        if (! preg_match('/^(.*?) mentioned (?:you|a user) in /u', $title, $match)) {
            return null;
        }

        $name = trim((string) ($match[1] ?? ''));
        if ($name === '') {
            return null;
        }

        $matches = User::query()
            ->where('name', $name)
            ->limit(2)
            ->get(['id', 'name', 'profile_image_path']);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function deliverRealtime(
        User $recipient,
        FlowNotification $notification,
        ?FlowJob $job,
        ?Task $task,
        ?Inquiry $inquiry = null,
        ?InquiryTask $inquiryTask = null,
    ): void {
        $reverb = app(ReverbChannelService::class);
        if (!$reverb->enabled()) return;

        if (FlowNotification::supportsActorIdentity()) {
            $notification->loadMissing('actor:id,name,profile_image_path');
            $actor = $notification->actor;
        } else {
            $actor = $this->legacyActorFromMentionTitle((string) $notification->title);
        }

        $payload = [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => app(MentionService::class)->displayText($notification->message),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_avatar_url' => $actor?->profileImageUrl(),
            'job_id' => $job?->id,
            'job_number' => $job?->displayOrderNumber(),
            'task_id' => $task?->id,
            'task_number' => $task?->task_number,
            'inquiry_id' => $inquiry?->id,
            'inquiry_number' => $inquiry?->inquiry_number,
            'inquiry_task_id' => $inquiryTask?->id,
            'url' => $this->urlFor($notification),
            'created_at' => $notification->created_at?->toIso8601String(),
            'unread_count' => $this->unreadCount($recipient),
        ];

        $this->queueUserRealtimeEvent($recipient, 'flowtrack.notification', $payload, $notification->id);
    }

    /**
     * Queue a private-user Reverb event without ever coupling WebSocket
     * availability to the database action that caused it.
     */
    private function queueUserRealtimeEvent(
        User $recipient,
        string $event,
        array $payload,
        ?int $notificationId = null,
    ): void {
        try {
            DeliverRealtimeNotification::dispatch(
                $recipient->id,
                $event,
                $payload,
            )
                ->onConnection((string) config('services.realtime.queue_connection', 'database'))
                ->afterCommit();
        } catch (\Throwable $exception) {
            // Realtime is an enhancement only. Queue/Reverb problems must never
            // turn a successful FlowTrack database action into a false failure.
            Log::warning('Realtime user event could not be queued.', [
                'notification_id' => $notificationId,
                'user_id' => $recipient->id,
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function forgetRecipientCaches(User $recipient): void
    {
        app(DashboardService::class)->forget($recipient->id);
        app(ReportService::class)->forget($recipient->id);
        app(ShellDataService::class)->forget($recipient->id);
    }

    private function runAfterCommit(callable $callback): void
    {
        // Notifications are a side effect, never part of the user's core save.
        // A notification/database fan-out problem after a successful commit must
        // not turn an inline edit into a false failure and make the UI roll back.
        $safeCallback = static function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $exception) {
                Log::warning('Post-commit notification work failed.', [
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($safeCallback);
            return;
        }

        $safeCallback();
    }

    private function administratorMentionTitle(string $title): string
    {
        $converted = str_replace(' mentioned you in ', ' mentioned a user in ', $title);
        return $converted !== $title ? $converted : 'Tagged comment: '.$title;
    }

    private function administratorIds(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_super_admin', true)
                    ->orWhereHas('roles', fn ($r) => $r->where('is_active', true)->whereIn('slug', ['super-admin','admin','administrator']))
                    ->orWhereHas('role', fn ($r) => $r->whereIn('slug', ['super-admin','admin','administrator']));
            })
            ->pluck('id');
    }
}
