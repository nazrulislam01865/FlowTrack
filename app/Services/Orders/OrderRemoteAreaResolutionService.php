<?php

namespace App\Services\Orders;

use App\DTOs\Orders\RemoteAreaResolution;
use App\Models\FlowJob;
use App\Services\MasterDataService;
use App\Services\RemoteAreas\PostalRangeCompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Single Order snapshot writer. Normal reads must never invoke this matcher. */
final class OrderRemoteAreaResolutionService
{
    public function __construct(private readonly MasterDataService $masterData) {}

    public function workspaceId(): int
    {
        return $this->masterData->workspaceId();
    }

    private function revision(int $workspaceId): int
    {
        return (int) (DB::table('remote_area_lookup_versions')
            ->where('workspace_id', $workspaceId)->value('revision') ?? 0);
    }

    public function resolveForInput(?string $postal): RemoteAreaResolution
    {
        $workspace = $this->workspaceId();
        $key = PostalRangeCompiler::key($postal);
        if ($key === '') return RemoteAreaResolution::resolved($workspace, '', 0, null);
        if (strlen($key) > 30) return RemoteAreaResolution::unresolved($workspace, $key);

        try {
            $before = $this->revision($workspace);
            // The canonical matcher uses the published Redis exact/range index
            // when ready; its safe legacy path remains the outage fallback.
            $record = $this->masterData->remoteAreaForPostalCode($postal);
            $after = $this->revision($workspace);
            if ($before !== $after) return RemoteAreaResolution::unresolved($workspace, $key);
            return RemoteAreaResolution::resolved($workspace, $key, $after, $record);
        } catch (Throwable $exception) {
            Log::warning('Remote Area lookup unavailable; Order resolution remains unresolved.', [
                'workspace_id' => $workspace, 'error_type' => $exception::class,
            ]);
            return RemoteAreaResolution::unresolved($workspace, $key);
        }
    }

    public function validSnapshot(FlowJob $job): ?RemoteAreaResolution
    {
        return RemoteAreaResolution::fromOrder($job, $this->workspaceId());
    }

    /** Used by an existing canonical Order save; caller must own the transaction/lock. */
    public function attributesForPostal(FlowJob $job, ?string $postal, ?RemoteAreaResolution $resolved = null): array
    {
        if (! config('remote_area_lookup.order_resolution_writes_enabled', false)) return [];
        $key = PostalRangeCompiler::key($postal);
        $workspace = $this->workspaceId();
        if (PostalRangeCompiler::key($job->shipping_postal_code) === $key
            && $this->validSnapshot($job) !== null) return [];
        $resolved ??= $this->resolveForInput($postal);
        if ($resolved->workspaceId !== $workspace || $resolved->key !== $key
            || $resolved->fingerprint !== RemoteAreaResolution::fingerprint($workspace, $postal)
            || ($resolved->complete() && $key !== '' && $resolved->revision !== $this->revision($workspace))) {
            // A concurrent Master Data update invalidated the prepared
            // candidate. Preserve the postal edit but fail closed for pricing.
            $resolved = RemoteAreaResolution::unresolved($workspace, $key);
        }
        return $resolved->columns();
    }

    /** For new Orders, after the final canonical primary shipment has been established. */
    public function persistForOrder(FlowJob $job): void
    {
        if (! config('remote_area_lookup.order_resolution_writes_enabled', false)) return;
        $job->refresh();
        $attributes = $this->attributesForPostal($job, $job->shipping_postal_code);
        if ($attributes !== []) $job->updateQuietly($attributes);
    }

    /** Called only on a locked Order at new-invoice finalization. */
    public function ensureFinanciallyValid(FlowJob $lockedJob): RemoteAreaResolution
    {
        $workspace = $this->workspaceId();
        // A locking read serializes invoice pricing with the existing observer's
        // revision increment. A new rule cannot silently change under this
        // financial checkpoint when the revision row exists.
        $revision = (int) (DB::table('remote_area_lookup_versions')
            ->where('workspace_id', $workspace)->lockForUpdate()->value('revision') ?? 0);
        $snapshot = $this->validSnapshot($lockedJob);
        if ($snapshot && ($snapshot->status === RemoteAreaResolution::NOT_APPLICABLE
            || ($snapshot->revision !== null && $snapshot->revision === $revision))) {
            return $snapshot;
        }
        $resolved = $this->resolveForInput($lockedJob->shipping_postal_code);
        abort_unless($resolved->complete() && ($resolved->key === '' || $resolved->revision === $revision),
            422, 'Remote Area pricing is temporarily unavailable. Please retry.');
        $lockedJob->updateQuietly($resolved->columns());
        return $resolved;
    }
}
