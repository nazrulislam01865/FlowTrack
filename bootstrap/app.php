<?php

use App\Http\Middleware\AddFlowTrackSecurityHeaders;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\FlowPermissionMiddleware;
use App\Http\Middleware\EnsureFlowWorkspaceMembership;
use App\Http\Middleware\FlowActionMiddleware;
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
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'flow.permission' => FlowPermissionMiddleware::class,
            'flow.action' => FlowActionMiddleware::class,
            'flow.workspace' => EnsureFlowWorkspaceMembership::class,
            'flow.bootstrap.invalidate' => InvalidateFlowTrackBootstrapCache::class,
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
