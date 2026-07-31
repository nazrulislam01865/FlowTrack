<?php

namespace App\Modules\OrderFlow\Application\Support;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Repository;

class FlowTrackBootstrapCache
{
    private const CACHE_SCHEMA_VERSION = 4;

    public function __construct(private readonly Repository $cache) {}

    public function remember(User $user, Closure $resolver): array
    {
        $workspaceId = (int) $user->currentWorkspaceId();
        $version = $this->version($workspaceId);
        $seconds = max(5, (int) config('flowtrack.bootstrap_cache_seconds', 45));

        return $this->cache->remember(
            $this->payloadKey($workspaceId, (int) $user->id, $version),
            $seconds,
            fn (): array => [
                ...$resolver(),
                'bootstrapVersion' => $version,
            ],
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

    public function etag(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function version(int $workspaceId): int
    {
        return max(1, (int) $this->cache->get($this->versionKey($workspaceId), 1));
    }

    private function versionKey(int $workspaceId): string
    {
        return "flowtrack:bootstrap:workspace:{$workspaceId}:version";
    }

    private function payloadKey(int $workspaceId, int $userId, int $version): string
    {
        return "flowtrack:bootstrap:schema:".self::CACHE_SCHEMA_VERSION.":workspace:{$workspaceId}:user:{$userId}:v{$version}";
    }
}
