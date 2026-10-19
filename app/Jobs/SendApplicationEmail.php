<?php

namespace App\Jobs;

use App\DTOs\Email\EmailMessage;
use App\Services\Email\EmailService;
use App\Services\Infrastructure\QueueTelemetry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SendApplicationEmail implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $maxExceptions;
    public array $backoff;
    public int $timeout;
    public bool $failOnTimeout = true;
    public readonly int $enqueuedAt;

    public function __construct(
        public readonly EmailMessage $message,
        public readonly string $trackingId,
    ) {
        $this->tries = max(1, (int) config('flowtrack_email.queue.tries', 100));
        $this->maxExceptions = max(1, (int) config('flowtrack_email.queue.max_exceptions', 4));
        $this->backoff = array_values(array_map(
            static fn ($seconds): int => max(1, (int) $seconds),
            (array) config('flowtrack_email.queue.backoff', [10, 60, 180]),
        ));
        $this->timeout = max(5, (int) config('flowtrack_email.queue.timeout', 30));
        $this->enqueuedAt = time();

        $queue = trim((string) config('flowtrack_email.queue.name', 'emails'));
        $connection = trim((string) config('flowtrack_email.queue.connection', ''));

        $this->onQueue($queue !== '' ? $queue : 'emails');
        if ($connection !== '') {
            $this->onConnection($connection);
        }
        $this->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('flowtrack-email')];
    }

    public function handle(EmailService $email, QueueTelemetry $telemetry): void
    {
        $telemetry->recordStart(self::class, $this->enqueuedAt, [
            'tracking_id' => $this->trackingId,
            'recipients' => count($this->message->to),
        ]);

        $email->deliver($this->message, $this->trackingId);
    }

    public function failed(?Throwable $exception): void
    {
        logger()->error('flowtrack.queue.email_failed', [
            'tracking_id' => $this->trackingId,
            'recipient_count' => count($this->message->to),
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
