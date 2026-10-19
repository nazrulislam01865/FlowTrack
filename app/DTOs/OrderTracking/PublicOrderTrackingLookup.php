<?php

namespace App\DTOs\OrderTracking;

use Illuminate\Support\Str;

final readonly class PublicOrderTrackingLookup
{
    public function __construct(
        public string $lookupType,
        public string $identifier,
        public string $email,
    ) {
    }

    /** @param array{lookup_type:string,identifier:string,email:string} $data */
    public static function fromValidated(array $data): self
    {
        return new self(
            lookupType: $data['lookup_type'] === 'reference' ? 'reference' : 'order',
            identifier: trim($data['identifier']),
            email: Str::lower(trim($data['email'])),
        );
    }
}
