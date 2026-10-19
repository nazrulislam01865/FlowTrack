<?php

namespace App\Jobs;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Infrastructure\QueueTelemetry;
use Throwable;

class FanOutFlowNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 20, 60];
    public int $timeout = 30;
    public bool $failOnTimeout = true;
    public readonly int $enqueuedAt;

    public function __construct(
        public readonly array $recipientIds,
        public readonly string $title,
        public readonly string $message,
        public readonly string $type = 'info',
        public readonly ?int $jobId = null,
        public readonly ?int $taskId = null,
        public readonly ?int $actorId = null,
    ) {
        $this->enqueuedAt = time();
        $this->onQueue('notifications');
    }

    public function handle(NotificationService $notifications, QueueTelemetry $telemetry): void
    {
        $telemetry->recordStart(self::class, $this->enqueuedAt, ['recipients' => count($this->recipientIds)]);
        $job = $this->jobId ? FlowJob::withTrashed()->find($this->jobId) : null;
        $task = $this->taskId ? Task::withTrashed()->find($this->taskId) : null;
        $actor = $this->actorId ? User::find($this->actorId) : null;
        $visibleJob = $job && !$job->trashed() ? $job : null;
        $visibleTask = $task && !$task->trashed() ? $task : null;

        User::query()
            ->whereIn('id', collect($this->recipientIds)->map(fn ($id) => (int) $id)->filter()->unique())
            ->where('is_active', true)
            ->eachById(function (User $recipient) use ($notifications, $visibleJob, $visibleTask, $actor): void {
                $notifications->notifyUser(
                    $recipient,
                    $this->title,
                    $this->message,
                    $this->type,
                    $visibleJob,
                    $visibleTask,
                    $actor,
                );
            });
    }
    public function failed(?Throwable $exception): void
    {
        logger()->error('flowtrack.queue.notification_fanout_failed', [
            'job_id' => $this->jobId,
            'task_id' => $this->taskId,
            'recipient_count' => count($this->recipientIds),
            'error' => $exception?->getMessage(),
        ]);
    }

}
