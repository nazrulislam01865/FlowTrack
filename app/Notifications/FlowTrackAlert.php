<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class FlowTrackAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 20, 60];

    public function __construct(private readonly array $payload) {}

    public function viaQueues(): array
    {
        return ['database' => 'notifications'];
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
