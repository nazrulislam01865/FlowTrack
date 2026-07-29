<?php

namespace App\Modules\OrderFlow\Application\DTOs;

final readonly class UpdateJobData
{
    public function __construct(
        public int $clientId,
        public ?int $currencyId,
        public ?int $priorityId,
        public ?int $ownerId,
        public ?int $coordinatorId,
        public string $title,
        public ?string $requestDescription,
        public ?float $quotedAmount,
        public ?string $deliveryDate,
        public array $productItems,
    ) {}
}
