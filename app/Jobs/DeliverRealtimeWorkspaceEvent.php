<?php

namespace App\Jobs;

use App\Services\ReverbChannelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use App\Services\Infrastructure\QueueTelemetry;
use Throwable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverRealtimeWorkspaceEvent implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 90];
    public int $timeout = 10;
    public int $uniqueFor = 300;
    public bool $failOnTimeout = true;
    public readonly int $enqueuedAt;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $event,
        public readonly array $payload,
    ) {
        $this->enqueuedAt = time();
        $this->onQueue((string) config('services.realtime.queue', 'realtime'));
    }

    public function handle(ReverbChannelService $reverb, QueueTelemetry $telemetry): void
    {
        $telemetry->recordStart(self::class, $this->enqueuedAt, ['workspace_id' => $this->workspaceId, 'event' => $this->event]);
        $reverb->triggerWorkspace($this->workspaceId, $this->event, $this->payload);
    }
    public function uniqueId(): string
    {
        $identity = $this->payload['version'] ?? hash('sha256', json_encode($this->payload) ?: 'payload');
        return 'realtime-workspace:'.$this->workspaceId.':'.$this->event.':'.$identity;
    }

    public function failed(?Throwable $exception): void
    {
        logger()->error('flowtrack.queue.realtime_workspace_failed', [
            'workspace_id' => $this->workspaceId,
            'event' => $this->event,
            'error' => $exception?->getMessage(),
        ]);
    }

}
