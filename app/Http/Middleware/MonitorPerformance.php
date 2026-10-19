<?php

namespace App\Http\Middleware;

use App\Support\Performance\RequestPerformanceMonitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MonitorPerformance
{
    public function __construct(private readonly RequestPerformanceMonitor $monitor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->monitor->start($request);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->monitor->finishException($request, $exception);
            throw $exception;
        }

        $this->monitor->finish($request, $response);

        return $response;
    }
}
