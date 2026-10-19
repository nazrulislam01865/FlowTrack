<?php

namespace App\Jobs;

use App\Services\SupplierBridge\DeliverOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverSupplierOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(DeliverOrder $delivery): void
    {
        $delivery->send($this->deliveryId);
    }
}
