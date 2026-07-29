<?php

namespace App\Modules\OrderFlow\Application\DTOs;

final readonly class CreateJobData
{
    public function __construct(
        public int $workspaceId,
        public int $clientId,
        public int $workflowTemplateId,
        public int $startPhaseId,
        public ?int $productId,
        public ?int $productCategoryId,
        public ?int $currencyId,
        public ?int $priorityId,
        public ?int $ownerId,
        public ?int $coordinatorId,
        public string $title,
        public int $quantity,
        public float $quotedAmount,
        public ?string $deliveryDate,
        public ?string $startReason,
        public array $memberIds = [],
        public bool $draft = false,
        public array $productItems = [],
        public ?string $requestDescription = null,
    ) {}
}
