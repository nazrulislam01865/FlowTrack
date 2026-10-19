<?php

namespace App\DTOs\Inquiries;

final readonly class InquiryCreateData
{
    public function __construct(public array $attributes)
    {
    }

    public static function fromArray(array $attributes): self
    {
        return new self($attributes);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
