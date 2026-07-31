<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Notifications\FlowTrackAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FlowNotificationService
{
    public function __construct(
        private readonly PusherChannelsClient $pusher,
        private readonly FlowAuthorizationService $authorization,
    ) {}

    public function jobCreated(int $jobId, int $actorId): void
    {
        $job = $this->job($jobId);
        if (! $job) return;

        $actor = User::query()->find($actorId);
        $phase = $job->currentPhase?->name ?: 'the starting phase';

        $this->notifyJobAudience($job, [
            'event' => 'job.created',
            'title' => 'New Job assigned',
            'text' => "{$job->code}: {$job->title} was created in {$phase}.",
            'type' => 'job',
            'severity' => 'info',
            'job' => $job->code,
            'job_id' => $job->id,
            'phase' => $phase,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/jobs/{$job->id}",
        ], null);

        $job->tasks()
            ->where('workflow_phase_id', $job->current_phase_id)
            ->whereNotNull('assignee_id')
            ->with(['assignee:id,name,status'])
            ->get()
            ->groupBy('assignee_id')
            ->each(function (Collection $tasks) use ($job, $actor, $phase): void {
                $assignee = $tasks->first()?->assignee;
                if (! $assignee) return;

                $count = $tasks->count();
                $this->send(
                    collect([$assignee])->merge($this->administrators($job->workspace_id)),
                    [
                        'event' => 'task.phase_assignment',
                        'title' => 'Starting Phase Tasks assigned',
                        'text' => "{$count} Task".($count === 1 ? '' : 's')." from {$phase} are assigned on {$job->code}.",
                        'type' => 'task',
                        'severity' => 'info',
                        'job' => $job->code,
                        'job_id' => $job->id,
                        'phase' => $phase,
                        'actor' => $actor?->name ?: 'System',
                        'url' => "/flowtrack/view/jobs/{$job->id}",
                    ],
                    null,
                );
            });
    }

    public function taskCreated(int $taskId, int $actorId): void
    {
        $task = $this->task($taskId);
        if (! $task || ! $task->job) return;

        $actor = User::query()->find($actorId);
        $recipients = $this->administrators($task->job->workspace_id);
        if ($task->assignee_id) {
            $recipients = $recipients->merge(User::query()->whereKey($task->assignee_id)->get());
        }

        $this->send($recipients, [
            'event' => 'task.assigned',
            'title' => 'New Task assigned',
            'text' => "{$task->code}: {$task->title} on {$task->job->code}.",
            'type' => 'task',
            'severity' => 'info',
            'job' => $task->job->code,
            'job_id' => $task->job->id,
            'task' => $task->code,
            'task_id' => $task->id,
            'phase' => $task->phase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/tasks/{$task->id}",
        ], null);
    }

    public function taskChanged(
        int $taskId,
        int $actorId,
        ?int $previousAssigneeId,
        ?string $previousStatus,
    ): void {
        $task = $this->task($taskId);
        if (! $task || ! $task->job) return;

        $actor = User::query()->find($actorId);
        $currentStatus = $task->status?->name ?: 'Updated';
        $assigneeChanged = (int) ($previousAssigneeId ?? 0) !== (int) ($task->assignee_id ?? 0);
        $statusChanged = trim((string) $previousStatus) !== trim((string) $currentStatus);

        if ($assigneeChanged && $task->assignee_id) {
            $this->send(
                $this->administrators($task->job->workspace_id)->merge(User::query()->whereKey($task->assignee_id)->get()),
                [
                    'event' => 'task.assigned',
                    'title' => 'Task assigned to you',
                    'text' => "{$task->code}: {$task->title} on {$task->job->code}.",
                    'type' => 'task',
                    'severity' => 'info',
                    'job' => $task->job->code,
                    'job_id' => $task->job->id,
                    'task' => $task->code,
                    'task_id' => $task->id,
                    'phase' => $task->phase?->name,
                    'actor' => $actor?->name ?: 'System',
                    'url' => "/flowtrack/view/tasks/{$task->id}",
                ],
                null,
            );
        }

        if ($statusChanged || $task->attention_required || filled($task->blocker_reason)) {
            $severity = ($task->attention_required || filled($task->blocker_reason)) ? 'warning' : 'info';
            $this->notifyJobAudience($task->job, [
                'event' => 'task.updated',
                'title' => $severity === 'warning' ? 'Task requires attention' : 'Task status updated',
                'text' => "{$task->code} is now {$currentStatus}.".($task->blocker_reason ? " {$task->blocker_reason}" : ''),
                'type' => $severity === 'warning' ? 'risk' : 'task',
                'severity' => $severity,
                'job' => $task->job->code,
                'job_id' => $task->job->id,
                'task' => $task->code,
                'task_id' => $task->id,
                'phase' => $task->phase?->name,
                'actor' => $actor?->name ?: 'System',
                'url' => "/flowtrack/view/tasks/{$task->id}",
            ], 'workflow_updates');
        }
    }

    /** @param array<int, int> $excludeUserIds */
    public function taskCommented(int $taskId, int $actorId, string $comment, array $excludeUserIds = []): void
    {
        $task = $this->task($taskId);
        if (! $task || ! $task->job || blank($comment)) return;

        $actor = User::query()->find($actorId);
        $recipientIds = collect([
            $task->assignee_id,
            $task->job->owner_id,
            $task->job->coordinator_id,
        ])->filter()->map(fn ($id): int => (int) $id)->unique();
        $recipients = User::query()->whereIn('id', $recipientIds)->get()
            ->merge($this->administrators($task->job->workspace_id))
            ->reject(fn (User $user): bool => in_array((int) $user->id, array_map('intval', $excludeUserIds), true));
        $excerpt = str(trim($comment))->squish()->limit(180)->toString();

        $this->send($recipients, [
            'event' => 'task.commented',
            'title' => 'New Task comment',
            'text' => "{$actor?->name}: {$excerpt}",
            'type' => 'mention',
            'severity' => 'info',
            'job' => $task->job->code,
            'job_id' => $task->job->id,
            'task' => $task->code,
            'task_id' => $task->id,
            'phase' => $task->phase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/tasks/{$task->id}",
        ], 'mentions_comments');
    }

    /** @param array<int, int> $mentionedUserIds */
    public function taskMentioned(int $taskId, int $actorId, string $comment, array $mentionedUserIds): void
    {
        $task = $this->task($taskId);
        if (! $task || ! $task->job || blank($comment) || empty($mentionedUserIds)) return;

        $actor = User::query()->find($actorId);
        $recipientIds = collect($mentionedUserIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $actorId)
            ->unique()
            ->values();
        if ($recipientIds->isEmpty()) return;

        $excerpt = str(trim($comment))->squish()->limit(180)->toString();
        $this->send(User::query()->whereIn('id', $recipientIds)->get(), [
            'event' => 'task.mentioned',
            'title' => 'You were mentioned in a Task comment',
            'text' => "{$actor?->name}: {$excerpt}",
            'type' => 'mention',
            'severity' => 'info',
            'job' => $task->job->code,
            'job_id' => $task->job->id,
            'task' => $task->code,
            'task_id' => $task->id,
            'phase' => $task->phase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/tasks/{$task->id}",
        ], 'mentions_comments');
    }

    public function taskDueDateChanged(int $taskId, int $actorId, ?string $fromDate, ?string $toDate): void
    {
        $task = $this->task($taskId);
        if (! $task || ! $task->job || $fromDate === $toDate) return;

        $actor = User::query()->find($actorId);
        $fromLabel = $fromDate ?: 'no due date';
        $toLabel = $toDate ?: 'no due date';

        $this->notifyJobAudience($task->job, [
            'event' => 'task.due_date_changed',
            'title' => 'Task due date changed',
            'text' => "{$task->code}: {$task->title} changed from {$fromLabel} to {$toLabel}.",
            'type' => 'task',
            'severity' => 'info',
            'job' => $task->job->code,
            'job_id' => $task->job->id,
            'task' => $task->code,
            'task_id' => $task->id,
            'phase' => $task->phase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/tasks/{$task->id}",
        ], 'workflow_updates');
    }

    /** @param array{id:int, code:string, title:string, phase:?string, assignee_id:?int, descendant_count?:int} $task */
    public function taskRemoved(int $jobId, array $task, int $actorId): void
    {
        $job = $this->job($jobId);
        if (! $job) return;

        $actor = User::query()->find($actorId);
        $descendants = max(0, (int) ($task['descendant_count'] ?? 0));
        $suffix = $descendants > 0 ? " and {$descendants} subtask".($descendants === 1 ? '' : 's') : '';

        $recipients = $this->jobAudience($job);
        if (filled($task['assignee_id'] ?? null)) {
            $recipients = $recipients->merge(User::query()->whereKey($task['assignee_id'])->get());
        }

        $this->send($recipients, [
            'event' => 'task.removed',
            'title' => 'Task removed from Job',
            'text' => "{$task['code']}: {$task['title']}{$suffix} was removed from {$job->code}.",
            'type' => 'task',
            'severity' => 'warning',
            'job' => $job->code,
            'job_id' => $job->id,
            'task' => $task['code'],
            'task_id' => $task['id'],
            'phase' => $task['phase'] ?? null,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/jobs/{$job->id}",
        ], 'workflow_updates');
    }

    public function jobPhaseRemoved(int $jobId, int $actorId, string $phaseName, ?string $replacementPhase, ?string $reason): void
    {
        $job = $this->job($jobId);
        if (! $job) return;

        $actor = User::query()->find($actorId);
        $replacement = $replacementPhase ? " The Job now continues in {$replacementPhase}." : '';
        $reasonText = filled($reason) ? ' Reason: '.trim((string) $reason) : '';

        $this->notifyJobAudience($job, [
            'event' => 'job.phase_removed',
            'title' => 'Job Phase removed',
            'text' => "{$phaseName} was removed from {$job->code}.{$replacement}{$reasonText}",
            'type' => 'workflow',
            'severity' => 'warning',
            'job' => $job->code,
            'job_id' => $job->id,
            'phase' => $replacementPhase ?: $job->currentPhase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/jobs/{$job->id}",
        ], 'workflow_updates');
    }

    public function jobPhaseChanged(int $jobId, int $actorId, ?string $fromPhase, ?string $toPhase): void
    {
        $job = $this->job($jobId);
        if (! $job) return;

        $actor = User::query()->find($actorId);
        $toPhase = $toPhase ?: $job->currentPhase?->name ?: 'the next phase';

        $this->notifyJobAudience($job, [
            'event' => 'job.phase_changed',
            'title' => 'Job Phase changed',
            'text' => "{$job->code} moved from ".($fromPhase ?: 'its previous Phase')." to {$toPhase}.",
            'type' => 'workflow',
            'severity' => 'info',
            'job' => $job->code,
            'job_id' => $job->id,
            'phase' => $toPhase,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/jobs/{$job->id}",
        ], 'workflow_updates');

        $newTasks = $job->tasks()
            ->where('workflow_phase_id', $job->current_phase_id)
            ->whereNotNull('assignee_id')
            ->with(['assignee:id,name,status', 'phase:id,name'])
            ->get()
            ->groupBy('assignee_id');

        foreach ($newTasks as $assigneeId => $tasks) {
            $assignee = $tasks->first()?->assignee;
            if (! $assignee) continue;

            $count = $tasks->count();
            $this->send(
                collect([$assignee])->merge($this->administrators($job->workspace_id)),
                [
                    'event' => 'task.phase_assignment',
                    'title' => 'New Phase Tasks assigned',
                    'text' => "{$count} Task".($count === 1 ? '' : 's')." from {$toPhase} are assigned on {$job->code}.",
                    'type' => 'task',
                    'severity' => 'info',
                    'job' => $job->code,
                    'job_id' => $job->id,
                    'phase' => $toPhase,
                    'actor' => $actor?->name ?: 'System',
                    'url' => "/flowtrack/view/jobs/{$job->id}",
                ],
                null,
            );
        }
    }

    public function phaseReady(int $jobId, ?int $actorId, string $phaseName): void
    {
        $job = $this->job($jobId);
        if (! $job) return;

        $actor = $actorId ? User::query()->find($actorId) : null;

        $this->send(
            $this->administrators($job->workspace_id),
            [
                'event' => 'job.phase_ready',
                'title' => 'Phase Ready to Complete',
                'text' => "{$job->code}: All tasks for {$phaseName} are completed. Please shift it to the next phase.",
                'type' => 'workflow',
                'severity' => 'success',
                'job' => $job->code,
                'job_id' => $job->id,
                'phase' => $phaseName,
                'actor' => $actor?->name ?: 'System',
                'url' => "/flowtrack/view/jobs/{$job->id}",
            ],
            'workflow_updates',
        );
    }

    public function documentUploaded(int $documentId, int $actorId, bool $phaseMoved = false): void
    {
        $document = FlowDocument::query()
            ->with(['job.currentPhase', 'category', 'phase', 'taskPackItem'])
            ->find($documentId);
        if (! $document || ! $document->job) return;

        $actor = User::query()->find($actorId);
        $category = $document->category?->name ?: 'Required document';
        $phase = $document->phase?->name ?: $document->job->currentPhase?->name;

        $this->notifyJobAudience($document->job, [
            'event' => 'document.uploaded',
            'title' => $phaseMoved ? 'Document uploaded and Job advanced' : 'Required document uploaded',
            'text' => "{$category} was linked to {$document->job->code}".($phase ? " for {$phase}" : '').'.',
            'type' => 'document',
            'severity' => 'success',
            'job' => $document->job->code,
            'job_id' => $document->job->id,
            'document_id' => $document->id,
            'phase' => $phase,
            'actor' => $actor?->name ?: 'System',
            'url' => '/flowtrack/documents',
        ], 'workflow_updates');
    }

    public function jobHealthChanged(int $jobId, int $actorId, string $from, string $to, ?string $reason, string $source): void
    {
        if (str_contains($source, 'repair') || str_contains($source, 'sync_workflow_state')) {
            return;
        }

        $job = $this->job($jobId);
        if (! $job) return;

        $actor = User::query()->find($actorId);
        $healthy = in_array($to, ['on_track', 'completed'], true);

        $this->notifyJobAudience($job, [
            'event' => 'job.health_changed',
            'title' => $healthy ? 'Job health recovered' : 'Job health alert',
            'text' => "{$job->code} changed from ".$this->label($from).' to '.$this->label($to).'.'.($reason ? " {$reason}" : ''),
            'type' => $healthy ? 'job' : 'risk',
            'severity' => $healthy ? 'success' : 'warning',
            'job' => $job->code,
            'job_id' => $job->id,
            'phase' => $job->currentPhase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/jobs/{$job->id}",
        ], 'workflow_updates');
    }

    public function jobMembersChanged(int $jobId, int $actorId, array $newMemberIds): void
    {
        $job = $this->job($jobId);
        if (! $job) return;

        $actor = User::query()->find($actorId);
        $recipients = User::query()->whereIn('id', $newMemberIds)->where('status', 'active')->get()
            ->merge($this->administrators($job->workspace_id));

        $this->send($recipients, [
            'event' => 'job.assignment_changed',
            'title' => 'Job assignment updated',
            'text' => "You are assigned to {$job->code}: {$job->title}.",
            'type' => 'job',
            'severity' => 'info',
            'job' => $job->code,
            'job_id' => $job->id,
            'phase' => $job->currentPhase?->name,
            'actor' => $actor?->name ?: 'System',
            'url' => "/flowtrack/view/jobs/{$job->id}",
        ], null);
    }

    /** @param array<string, mixed> $payload */
    private function notifyJobAudience(FlowJob $job, array $payload, ?string $preference): void
    {
        $this->send($this->jobAudience($job), $payload, $preference);
    }

    /** @param Collection<int, User> $users @param array<string, mixed> $payload */
    private function send(Collection $users, array $payload, ?string $preference): void
    {
        $payload['event_id'] = $payload['event_id'] ?? (string) Str::uuid();
        $payload['occurred_at'] = $payload['occurred_at'] ?? now()->toIso8601String();
        $payload['workspace_id'] = $payload['workspace_id']
            ?? (filled($payload['job_id'] ?? null)
                ? FlowJob::query()->whereKey($payload['job_id'])->value('workspace_id')
                : null);

        $recipients = $users
            ->filter(fn ($user): bool => $user instanceof User && $user->status === 'active')
            ->unique('id')
            ->filter(fn (User $user): bool => $this->shouldReceive($user, $preference))
            ->values();

        $payload['audience'] = $recipients
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();

        $channels = collect();
        $recipients->each(function (User $user) use ($payload, $channels): void {
            try {
                $user->notify(new FlowTrackAlert([
                    ...$payload,
                    'recipient_user_id' => $user->id,
                    'recipient_name' => $user->name,
                ]));
                $channels->push("private-flowtrack.user.{$user->id}");
            } catch (Throwable $exception) {
                Log::warning('FlowTrack notification dispatch failed.', [
                    'user_id' => $user->id,
                    'event' => $payload['event'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        });

        // Database notifications are the source of truth. Pusher is an optional
        // low-latency transport; the clients also poll the status endpoint so a
        // bad or missing Pusher configuration cannot hide notifications.
        if ($channels->isNotEmpty()) {
            $this->pusher->trigger(
                $channels->unique()->values()->all(),
                'notification.created',
                [
                    ...$payload,
                    'read' => false,
                    'time' => 'Just now',
                ],
            );
        }
    }

    private function shouldReceive(User $user, ?string $preference): bool
    {
        $roleCode = strtoupper((string) $user->currentWorkspaceMembership()?->role?->code);
        $authorityCodes = collect(config('flowtrack.notification_authority_role_codes', []))
            ->map(fn ($code): string => strtoupper((string) $code))
            ->all();

        if ($this->authorization->isAdministrator($user)
            || in_array($roleCode, $authorityCodes, true)
            || $preference === null) {
            return true;
        }

        return (bool) data_get($user->notification_preferences ?? [], $preference, true);
    }

    /** @return Collection<int, User> */
    private function jobAudience(FlowJob $job): Collection
    {
        $assignedIds = collect([$job->owner_id, $job->coordinator_id])
            ->merge($job->members()->pluck('user_id'))
            ->merge($job->tasks()->whereNotNull('assignee_id')->pluck('assignee_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();

        $assigned = User::query()
            ->whereIn('id', $assignedIds)
            ->where('status', 'active')
            ->where(function (Builder $query) use ($job): void {
                $query->where('is_super_admin', true)
                    ->orWhereHas('workspaceMemberships', fn (Builder $membership) => $membership
                        ->where('workspace_id', $job->workspace_id)
                        ->where('status', 'active'));
            })
            ->get();

        return $assigned->merge($this->administrators($job->workspace_id))->unique('id')->values();
    }

    /** @return Collection<int, User> */
    private function administrators(int $workspaceId): Collection
    {
        $adminCodes = collect(config('flowtrack.administrator_role_codes', []))
            ->merge(config('flowtrack.notification_authority_role_codes', []))
            ->map(fn ($code): string => strtoupper((string) $code))
            ->all();

        return User::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($workspaceId, $adminCodes): void {
                $query->where('is_super_admin', true)
                    ->orWhereHas('workspaceMemberships', fn (Builder $membership) => $membership
                        ->where('workspace_id', $workspaceId)
                        ->where('status', 'active')
                        ->whereHas('role', fn (Builder $role) => $role
                            ->where('is_active', true)
                            ->whereIn('code', $adminCodes)));
            })
            ->get();
    }

    private function job(int $jobId): ?FlowJob
    {
        return FlowJob::query()
            ->with(['currentPhase:id,name,short_name', 'members:user_id'])
            ->find($jobId);
    }

    private function task(int $taskId): ?FlowTask
    {
        return FlowTask::query()
            ->with(['job.currentPhase', 'phase:id,name,short_name', 'status:id,name,code', 'assignee:id,name,status'])
            ->find($taskId);
    }

    private function label(string $value): string
    {
        return str($value)->replace(['_', '-'], ' ')->title()->toString();
    }
}
