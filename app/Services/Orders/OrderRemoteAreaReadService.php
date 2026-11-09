<?php

namespace App\Services\Orders;

use App\DTOs\Orders\RemoteAreaResolution;
use App\Models\FlowJob;

/** Pure snapshot presenter: no Redis, MasterRecord or SQL query. */
final class OrderRemoteAreaReadService
{
    public function forDisplay(FlowJob $job, int $workspaceId): array
    {
        $snapshot = RemoteAreaResolution::fromOrder($job, $workspaceId);
        if (! $snapshot) return ['status' => 'unresolved', 'remoteArea' => null];
        if ($snapshot->status !== RemoteAreaResolution::MATCHED) {
            return ['status' => $snapshot->status, 'remoteArea' => null];
        }
        return ['status' => 'matched', 'remoteArea' => [
            'id' => $snapshot->ruleId,
            'name' => $snapshot->name,
            'postal_code' => (string) preg_replace('/\s+/', ' ', strtoupper(trim((string) $job->shipping_postal_code))),
            'location' => $snapshot->location,
            'extra_charge' => (float) $snapshot->charge,
        ]];
    }
}
