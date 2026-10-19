<?php

namespace App\DTOs\OrderTracking;

final readonly class PublicOrderTrackingCandidate
{
    public function __construct(
        public int $orderId,
        public ?int $clientId,
        public ?int $ownerId,
        public ?int $coordinatorId,
        public ?int $creatorId,
        public ?int $supplierId,
    ) {
    }
}
