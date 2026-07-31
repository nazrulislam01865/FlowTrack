<?php

namespace App\Http\Middleware;

use App\Modules\OrderFlow\Application\Support\FlowTrackBootstrapCache;
use App\Modules\OrderFlow\Application\Support\FlowTrackMetricsCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvalidateFlowTrackBootstrapCache
{
    public function __construct(
        private readonly FlowTrackBootstrapCache $cache,
        private readonly FlowTrackMetricsCache $metricsCache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->getStatusCode() < 400) {
            $workspaceId = $request->user()?->currentWorkspaceId();
            $this->cache->invalidateWorkspace($workspaceId);
            $this->metricsCache->invalidateWorkspace($workspaceId);
        }

        return $response;
    }
}
