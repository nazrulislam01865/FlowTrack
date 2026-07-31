<?php

namespace App\Modules\OrderFlow\Application\Support;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Repository;

class FlowTrackMetricsCache
{
    private const CACHE_SCHEMA_VERSION = 1;

    public function __construct(private readonly Repository $cache) {}

    public function remember(User $user, Closure $resolver): array
    {
        $workspaceId = (int) $user->currentWorkspaceId();
        $version = $this->version($workspaceId);
        $seconds = max(60, min(300, (int) config('flowtrack.metrics_cache_seconds', 120)));

        return $this->cache->remember(
            $this->payloadKey($workspaceId, (int) $user->id, $version),
            $seconds,
            fn (): array => $resolver(),
        );
    }

    public function invalidateWorkspace(?int $workspaceId): void
    {
        if (! $workspaceId) {
            return;
        }

        $this->cache->forever(
            $this->versionKey($workspaceId),
            $this->version($workspaceId) + 1,
        );
    }

    private function version(int $workspaceId): int
    {
        return max(1, (int) $this->cache->get($this->versionKey($workspaceId), 1));
    }

    private function versionKey(int $workspaceId): string
    {
        return "flowtrack:metrics:workspace:{$workspaceId}:version";
    }

    private function payloadKey(int $workspaceId, int $userId, int $version): string
    {
        $date = now()->toDateString();

        return "flowtrack:metrics:schema:".self::CACHE_SCHEMA_VERSION.":workspace:{$workspaceId}:user:{$userId}:date:{$date}:v{$version}";
    }
}
