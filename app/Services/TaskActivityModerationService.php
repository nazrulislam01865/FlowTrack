<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\FlowNotification;
use App\Models\FlowTaskComment;
use App\Models\Inquiry;
use App\Models\InquiryTaskComment;
use App\Models\Task;
use App\Models\User;
use App\Services\Orders\OrderHoldService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskActivityModerationService
{
    public function canModerate(User $user): bool
    {
        return app(AccessControlService::class)->isAdministrator($user);
    }

    public function deleteOrderTaskComment(Task $task, int $commentId, User $actor): void
    {
        $this->assertModerator($actor);
        app(OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);

        $recipientIds = DB::transaction(function () use ($task, $commentId, $actor): array {
            $comment = FlowTaskComment::query()
                ->where('flow_task_id', $task->id)
                ->with('user:id,name')
                ->lockForUpdate()
                ->findOrFail($commentId);

            $authorName = $comment->user?->name ?: 'Unknown user';
            $body = (string) $comment->body;
            $plain = app(RichTextService::class)->plainText($body);
            $sourceTime = $comment->created_at;

            $pairedActivities = $task->activities()
                ->where('event', 'task.comment')
                ->whereBetween('created_at', [$sourceTime->copy()->subMinutes(2), $sourceTime->copy()->addMinutes(2)])
                ->get()
                ->filter(fn (Activity $activity) => (int) data_get($activity->meta, 'comment_id') === (int) $comment->id);

            foreach ($pairedActivities as $activity) {
                $this->deleteOrderMirrorActivities($task, $activity);
                $activity->delete();
            }

            $recipientIds = $this->deleteMatchingNotifications(
                taskId: (int) $task->id,
                inquiryTaskId: null,
                sourceUserId: $comment->user_id ? (int) $comment->user_id : null,
                sourceTime: $sourceTime,
                messages: array_values(array_unique(array_filter([$body, 'Comment: '.$plain]))),
            );

            $comment->delete();

            $this->recordOrderAudit(
                $task,
                $actor,
                $actor->name.' deleted a task comment posted by '.$authorName.'.',
                [
                    'deleted_kind' => str_contains($body, '@') ? 'mention_comment' : 'comment',
                    'deleted_comment_id' => $commentId,
                    'deleted_user_id' => $comment->user_id,
                    'deleted_event' => 'task.comment',
                ],
            );

            return $recipientIds;
        });

        $this->refreshMentionRecipients($recipientIds);
    }

    public function deleteOrderTaskActivity(Task $task, int $activityId, User $actor): void
    {
        $this->assertModerator($actor);
        app(OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);

        $recipientIds = DB::transaction(function () use ($task, $activityId, $actor): array {
            $activity = $task->activities()
                ->with('user:id,name')
                ->lockForUpdate()
                ->findOrFail($activityId);

            abort_if($this->isProtectedAuditEvent((string) $activity->event), 422, 'Deletion audit entries cannot be removed.');
            abort_if((string) $activity->event === 'task.comment', 422, 'Delete the comment entry instead.');

            $authorName = $activity->user?->name ?: 'System';
            $event = (string) $activity->event;
            $description = (string) $activity->description;
            $sourceTime = $activity->created_at;

            $this->deleteOrderMirrorActivities($task, $activity);

            $recipientIds = $this->deleteMatchingNotifications(
                taskId: (int) $task->id,
                inquiryTaskId: null,
                sourceUserId: $activity->user_id ? (int) $activity->user_id : null,
                sourceTime: $sourceTime,
                messages: [$description],
            );

            $activity->delete();

            $label = Str::headline(str_replace(['task.', 'job.'], '', $event));
            $this->recordOrderAudit(
                $task,
                $actor,
                $actor->name.' deleted task activity "'.$label.'" recorded by '.$authorName.'.',
                [
                    'deleted_kind' => 'activity',
                    'deleted_activity_id' => $activityId,
                    'deleted_user_id' => $activity->user_id,
                    'deleted_event' => $event,
                ],
            );

            return $recipientIds;
        });

        $this->refreshMentionRecipients($recipientIds);
    }

    public function deleteInquiryTaskActivity(Inquiry $inquiry, int $activityId, User $actor): void
    {
        $this->assertModerator($actor);

        $recipientIds = DB::transaction(function () use ($inquiry, $activityId, $actor): array {
            $activity = $inquiry->activities()
                ->with('user:id,name')
                ->lockForUpdate()
                ->findOrFail($activityId);

            abort_if($this->isProtectedAuditEvent((string) $activity->event), 422, 'Deletion audit entries cannot be removed.');

            $taskId = (int) data_get($activity->meta, 'inquiry_task_id', 0);
            abort_if($taskId <= 0, 422, 'Only Inquiry task activity can be deleted here.');

            $task = $inquiry->tasks()->withTrashed()->findOrFail($taskId);
            $event = (string) $activity->event;
            $description = (string) $activity->description;
            $sourceTime = $activity->created_at;
            $sourceUserId = $activity->user_id ? (int) $activity->user_id : null;
            $authorName = $activity->user?->name ?: 'System';
            $deletedKind = 'activity';
            $deletedCommentId = null;
            $notificationMessages = [$description];
            $isTaskComment = $event === 'inquiry.task_comment'
                || (bool) data_get($activity->meta, 'comment', false)
                || (bool) data_get($activity->meta, 'attention_reason', false);

            if ($isTaskComment) {
                $deletedCommentId = (int) data_get($activity->meta, 'inquiry_task_comment_id', 0);
                $candidateBodies = collect([$description]);

                if (str_contains($description, ' — ')) {
                    $candidateBodies->push(Str::afterLast($description, ' — '));
                }

                $comment = null;
                if ($deletedCommentId > 0) {
                    $comment = InquiryTaskComment::query()
                        ->where('inquiry_task_id', $task->id)
                        ->find($deletedCommentId);
                }

                if (! $comment) {
                    $commentQuery = InquiryTaskComment::query()
                        ->where('inquiry_task_id', $task->id)
                        ->when($sourceUserId, fn ($query) => $query->where('user_id', $sourceUserId))
                        ->whereBetween('created_at', [$sourceTime->copy()->subMinutes(2), $sourceTime->copy()->addMinutes(2)]);

                    $comment = $commentQuery
                        ->whereIn('body', $candidateBodies->filter()->unique()->values()->all())
                        ->first();
                }

                if ($comment) {
                    $deletedCommentId = (int) $comment->id;
                    $commentBody = (string) $comment->body;
                    $notificationMessages[] = $commentBody;
                    if (str_starts_with($commentBody, 'Attention required: ')) {
                        $notificationMessages[] = Str::after($commentBody, 'Attention required: ');
                    }
                    $deletedKind = str_contains($commentBody, '@') ? 'mention_comment' : 'comment';
                    $comment->delete();
                } else {
                    $deletedKind = str_contains($description, '@') ? 'mention_comment' : 'comment';
                }
            }

            $recipientIds = $this->deleteMatchingNotifications(
                taskId: null,
                inquiryTaskId: $taskId,
                sourceUserId: $sourceUserId,
                sourceTime: $sourceTime,
                messages: array_values(array_unique(array_filter($notificationMessages))),
            );

            $activity->delete();

            $descriptionText = $deletedKind === 'activity'
                ? $actor->name.' deleted task activity "'.Str::headline(str_replace('inquiry.', '', $event)).'" recorded by '.$authorName.' from '.$task->title.'.'
                : $actor->name.' deleted a task comment posted by '.$authorName.' from '.$task->title.'.';

            $inquiry->activities()->create([
                'user_id' => $actor->id,
                'event' => 'inquiry.task_moderation_deleted',
                'description' => $descriptionText,
                'meta' => [
                    'inquiry_task_id' => $taskId,
                    'deleted_kind' => $deletedKind,
                    'deleted_activity_id' => $activityId,
                    'deleted_comment_id' => $deletedCommentId,
                    'deleted_user_id' => $sourceUserId,
                    'deleted_event' => $event,
                    'moderated_by_role' => $this->moderatorRole($actor),
                ],
            ]);

            return $recipientIds;
        });

        $this->refreshMentionRecipients($recipientIds);
    }

    private function recordOrderAudit(Task $task, User $actor, string $description, array $meta): void
    {
        $meta = array_merge($meta, [
            'task_id' => (int) $task->id,
            'moderated_by_role' => $this->moderatorRole($actor),
        ]);

        $task->activities()->create([
            'user_id' => $actor->id,
            'event' => 'task.moderation_deleted',
            'description' => $description,
            'meta' => $meta,
        ]);

        $job = $task->job()->first();
        if ($job) {
            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.task_activity',
                'description' => $task->title.': '.$description,
                'meta' => array_merge([
                    'task_id' => (int) $task->id,
                    'task_number' => $task->task_number,
                    'task_event' => 'task.moderation_deleted',
                ], $meta),
            ]);
        }
    }

    private function deleteOrderMirrorActivities(Task $task, Activity $source): void
    {
        $job = $task->job()->first();
        if (! $job) return;

        $from = $source->created_at?->copy()->subMinutes(2);
        $to = $source->created_at?->copy()->addMinutes(2);

        $candidates = $job->activities()
            ->where('event', 'job.task_activity')
            ->when($source->user_id, fn ($query) => $query->where('user_id', $source->user_id))
            ->when($from && $to, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
            ->get();

        $commentId = (int) data_get($source->meta, 'comment_id', 0);
        foreach ($candidates as $mirror) {
            $sameTask = (int) data_get($mirror->meta, 'task_id', 0) === (int) $task->id;
            $sameEvent = (string) data_get($mirror->meta, 'task_event', '') === (string) $source->event;
            $sameComment = $commentId > 0 && (int) data_get($mirror->meta, 'comment_id', 0) === $commentId;
            $sameDescription = (string) $mirror->description === $task->title.': '.(string) $source->description;

            if ($sameTask && ($sameComment || ($sameEvent && $sameDescription))) {
                $mirror->delete();
            }
        }
    }

    /** @return list<int> */
    private function deleteMatchingNotifications(?int $taskId, ?int $inquiryTaskId, ?int $sourceUserId, $sourceTime, array $messages): array
    {
        $messages = array_values(array_unique(array_filter(array_map(fn ($message) => trim((string) $message), $messages))));
        if ($messages === []) return [];

        $query = FlowNotification::query();
        if ($taskId) $query->where('flow_task_id', $taskId);
        if ($inquiryTaskId) $query->where('inquiry_task_id', $inquiryTaskId);
        if ($sourceUserId && FlowNotification::supportsActorIdentity()) $query->where('actor_id', $sourceUserId);
        if ($sourceTime) $query->whereBetween('created_at', [$sourceTime->copy()->subMinutes(3), $sourceTime->copy()->addMinutes(3)]);
        $query->whereIn('message', $messages);

        $recipientIds = (clone $query)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $query->delete();

        return $recipientIds;
    }

    private function refreshMentionRecipients(array $recipientIds): void
    {
        if ($recipientIds === []) return;

        User::query()->whereIn('id', $recipientIds)->get()->each(function (User $recipient): void {
            app(DashboardService::class)->forgetMentions($recipient);
            app(ShellDataService::class)->forget((int) $recipient->id);
            app(NotificationService::class)->broadcastRealtimeState($recipient);
        });
    }

    private function assertModerator(User $actor): void
    {
        abort_unless($this->canModerate($actor), 403, 'Only Admin or Super Admin can delete task activity.');
    }

    private function moderatorRole(User $actor): string
    {
        return $actor->isSuperAdmin() ? 'Super Admin' : 'Admin';
    }

    private function isProtectedAuditEvent(string $event): bool
    {
        return in_array($event, ['task.moderation_deleted', 'inquiry.task_moderation_deleted'], true);
    }
}
