<?php

namespace App\Http\Middleware;

use App\Modules\OrderFlow\Application\Support\FlowTrackBootstrapCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvalidateFlowTrackBootstrapCache
{
    public function __construct(private readonly FlowTrackBootstrapCache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->getStatusCode() < 400) {
            $this->cache->invalidateWorkspace($request->user()?->currentWorkspaceId());
        }

        return $response;
    }
}
