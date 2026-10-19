<?php

namespace App\DTOs\OrderTracking;

/**
 * Internal-only identity match result.
 *
 * Source names are deliberately never sent to the public tracking view. They
 * exist so the next backend phase can apply audience-specific disclosure rules
 * without having to broaden the public query again.
 */
final readonly class PublicOrderTrackingIdentity
{
    /** @param list<string> $sources */
    public function __construct(public array $sources)
    {
    }

    public function matched(): bool
    {
        return $this->sources !== [];
    }

    public function has(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }
}
