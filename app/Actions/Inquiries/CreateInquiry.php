<?php

namespace App\Actions\Inquiries;

use App\DTOs\Inquiries\InquiryCreateData;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class CreateInquiry
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(array $data, User $actor, bool $draft = false): Inquiry
    {
        $payload = InquiryCreateData::fromArray($data);
        return $this->inquiries->create($payload->toArray(), $actor, $draft);
    }
}
