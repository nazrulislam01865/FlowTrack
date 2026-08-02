<?php

use App\Http\Middleware\AddFlowTrackSecurityHeaders;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\FlowPermissionMiddleware;
use App\Http\Middleware\EnsureFlowWorkspaceMembership;
use App\Http\Middleware\FlowActionMiddleware;
use App\Http\Middleware\FlowRouteAccessMiddleware;
use App\Http\Middleware\EnforceFlowTrackSession;
use App\Http\Middleware\InvalidateFlowTrackBootstrapCache;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'flow.permission' => FlowPermissionMiddleware::class,
            'flow.action' => FlowActionMiddleware::class,
            'flow.workspace' => EnsureFlowWorkspaceMembership::class,
            'flow.bootstrap.invalidate' => InvalidateFlowTrackBootstrapCache::class,
            'flow.route.access' => FlowRouteAccessMiddleware::class,
        ]);

        $middleware->web(append: [
            AddFlowTrackSecurityHeaders::class,
            HandleAppearance::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnforceFlowTrackSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
