<?php

namespace App\Jobs;

use App\Modules\OrderFlow\Business\Services\PusherChannelsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverPusherEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 20, 60];

    /**
     * @param array<int, string> $channels
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $event,
        public readonly array $payload,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(PusherChannelsClient $pusher): void
    {
        $pusher->trigger($this->channels, $this->event, $this->payload);
    }
}
