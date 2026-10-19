<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSingleLoginSession;
use App\Http\Middleware\MonitorPerformance;
use App\Http\Middleware\NormalizeSessionCookie;
use App\Http\Middleware\PreventDynamicPageCaching;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Controllers\Health\InfrastructureReadyController;
use App\Http\Controllers\Telemetry\RealtimeTelemetryController;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Stateless readiness check for Nginx/load balancers. `/up` remains
            // the cheap process-liveness endpoint; this route verifies shared
            // dependencies without creating a session on the selected node.
            Route::get('/health/ready', InfrastructureReadyController::class)
                ->name('health.ready');

            Route::get('/api/supplier-bridge/rfq-documents/{invitation}/{document}', \App\Http\Controllers\SupplierBridge\DownloadRfqDocument::class)
                ->whereNumber('invitation')->whereNumber('document')->middleware('throttle:60,1');
            Route::get('/api/supplier-bridge/rfq-task-documents/{invitation}/{document}', \App\Http\Controllers\SupplierBridge\DownloadRfqDocument::class)
                ->whereNumber('invitation')->whereNumber('document')->defaults('task_document', true)
                ->middleware('throttle:supplier-inquiry-task-document-bridge');

            Route::get('/api/supplier-bridge/order-artwork/{order}/{document}', \App\Http\Controllers\SupplierBridge\DownloadOrderArtwork::class)
                ->whereNumber('order')->whereNumber('document')->middleware('throttle:60,1');

            Route::post('/api/supplier-bridge/rfq-quotation-files', \App\Http\Controllers\SupplierBridge\ReceiveSupplierQuotationFile::class)
                ->middleware('throttle:30,1');
            Route::post('/api/supplier-bridge/inquiry-task-actions', \App\Http\Controllers\SupplierBridge\ReceiveSupplierInquiryTaskAction::class)
                ->middleware('throttle:supplier-inquiry-task-bridge');
            Route::post('/api/supplier-bridge/rfq-quotations', \App\Http\Controllers\SupplierBridge\ReceiveSupplierQuotation::class)
                ->middleware('throttle:30,1');
            Route::post('/api/supplier-bridge/workflow-task-actions', \App\Http\Controllers\SupplierBridge\ReceiveSupplierTaskAction::class)
                ->middleware('throttle:20,1');
            Route::post('/api/supplier-bridge/realtime-auth', \App\Http\Controllers\SupplierBridge\AuthorizeSupplierRealtime::class)
                ->middleware('throttle:60,1');

            Route::post('/api/supplier-bridge/price-proposals', \App\Http\Controllers\SupplierBridge\ReceiveSupplierPriceProposal::class)
                ->middleware('throttle:30,1');

            Route::post('/telemetry/realtime', RealtimeTelemetryController::class)
                ->middleware(['web', 'auth', 'throttle:60,1'])
                ->name('telemetry.realtime');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super.admin' => EnsureSuperAdmin::class,
            'permission' => EnsurePermission::class,
        ]);

        // This must run before Laravel's StartSession middleware. It prevents
        // SESSION_DOMAIN / Secure / SameSite transport mismatches from causing
        // a fresh browser or alternate host to lose its session and hit 419.
        $middleware->web(
            prepend: [NormalizeSessionCookie::class],
            append: [MonitorPerformance::class, EnsureSingleLoginSession::class, PreventDynamicPageCaching::class, SecurityHeaders::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Never expose Laravel's raw "419 Page Expired" screen to users. CSRF
        // stays fully enabled; on a mismatch we send the browser through a
        // clean session recovery GET so the next page receives a fresh token.
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            $recoverUrl = route('session.recover');

            if ($request->expectsJson() || $request->ajax() || $request->header('X-Livewire')) {
                return response()->json([
                    'message' => 'Your secure session changed and needs to be refreshed.',
                    'redirect' => $recoverUrl,
                ], 419)->withHeaders([
                    'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                ]);
            }

            return redirect()->to($recoverUrl)->withHeaders([
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        });
    })->create();
