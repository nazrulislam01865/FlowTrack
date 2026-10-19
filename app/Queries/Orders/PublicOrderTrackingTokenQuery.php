<?php

namespace App\Queries\Orders;

use App\Models\FlowJob;
use App\Queries\OrderTracking\PublicOrderTrackingDataQuery;

/**
 * Opaque-token entry point for QR/direct public tracking.
 *
 * The token query reuses the same bounded public tracking data loader used by
 * the email-verified path, so the public UI never reads main-system tables
 * directly and cannot accidentally load private order relations.
 */
final class PublicOrderTrackingTokenQuery
{
    public function __construct(private readonly PublicOrderTrackingDataQuery $orders)
    {
    }

    public function find(string $token): ?FlowJob
    {
        return $this->orders->fetchByTrackingToken($token);
    }
}
