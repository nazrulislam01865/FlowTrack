<?php

namespace App\Contracts\Email;

use App\DTOs\Email\EmailMessage;

interface EmailTransport
{
    /**
     * Deliver one already-built application email through the active provider.
     *
     * $idempotencyKey is stable across queue retries. Transports that support
     * provider-side deduplication (such as e2a) should use it; transports that
     * do not need it may ignore it.
     */
    public function send(EmailMessage $message, ?string $idempotencyKey = null): void;
}
