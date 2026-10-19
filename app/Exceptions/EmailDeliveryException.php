<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class EmailDeliveryException extends RuntimeException
{
    public static function forTrackingId(string $trackingId, Throwable $previous): self
    {
        return new self(
            'Email delivery failed. Reference: '.$trackingId,
            (int) $previous->getCode(),
            $previous,
        );
    }
}
