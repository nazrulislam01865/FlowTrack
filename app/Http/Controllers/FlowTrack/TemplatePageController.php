<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\FlowLandingPageResolver;
use App\Modules\OrderFlow\Business\Services\WorkspaceBrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class TemplatePageController extends Controller
{

    private function authorizeInitialPath(Request $request, FlowAuthorizationService $authorization): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        $path = '/' . trim($request->path(), '/');
        $requirement = $this->pageRequirement($path);

        if ($requirement === null) {
            return;
        }

        $allowed = is_callable($requirement)
            ? (bool) $requirement($user, $authorization)
            : $authorization->allows($user, $requirement[0], $requirement[1]);

        abort_unless($allowed, 403);
    }

    private function pageRequirement(string $path): array|callable|null
    {
        if ($path === '/dashboard' || $path === '/flowtrack' || $path === '/flowtrack/dashboard' || $path === '/flowtrack/view/dashboard') {
            return ['dashboard', 'view'];
        }

        if ($path === '/flowtrack/view/board') {
            return fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'jobs', 'view') || $authorization->allows($user, 'tasks', 'view');
        }

        if ($path === '/flowtrack/view/mywork' || str_starts_with($path, '/flowtrack/view/tasks/')) {
            return ['tasks', 'view'];
        }

        if (str_starts_with($path, '/flowtrack/view/jobs/new')) {
            return ['jobs', 'create'];
        }

        if (preg_match('#^/flowtrack/view/jobs/[^/]+/edit$#', $path) === 1) {
            return fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'jobs', 'edit_own') || $authorization->allows($user, 'jobs', 'edit_all');
        }

        if ($path === '/flowtrack/view/jobs' || str_starts_with($path, '/flowtrack/view/jobs/')) {
            return ['jobs', 'view'];
        }

        if (str_starts_with($path, '/flowtrack/view/clients/new')) {
            return ['clients', 'create'];
        }

        if (preg_match('#^/flowtrack/view/clients/[^/]+/edit$#', $path) === 1) {
            return fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'clients', 'edit_own') || $authorization->allows($user, 'clients', 'edit_all');
        }

        if ($path === '/flowtrack/view/clients' || str_starts_with($path, '/flowtrack/view/clients/')) {
            return ['clients', 'view'];
        }

        if ($path === '/flowtrack/view/documents' || str_starts_with($path, '/flowtrack/view/documents/')) {
            return ['documents', 'view'];
        }

        if ($path === '/flowtrack/view/reports') {
            return ['reports', 'view'];
        }

        if ($path === '/flowtrack/view/masterdata') {
            return ['masterdata', 'view'];
        }

        if ($path === '/flowtrack/view/workflows/new' || $path === '/flowtrack/view/task-packs/new') {
            return ['workflow', 'create'];
        }

        if (preg_match('#^/flowtrack/view/(workflows|task-packs)/[^/]+/edit$#', $path) === 1) {
            return fn ($user, FlowAuthorizationService $authorization): bool => $authorization->allows($user, 'workflow', 'edit_own') || $authorization->allows($user, 'workflow', 'edit_all');
        }

        if ($path === '/flowtrack/view/workflowsetup'
            || str_starts_with($path, '/flowtrack/view/workflows')
            || $path === '/flowtrack/view/taskpacksetup'
            || str_starts_with($path, '/flowtrack/view/task-packs')) {
            return ['workflow', 'view'];
        }

        if ($path === '/flowtrack/view/setup') {
            return ['users', 'view'];
        }

        return null;
    }

    public function __invoke(Request $request, WorkspaceBrandingService $branding, FlowLandingPageResolver $landing): Response|RedirectResponse
    {
        if ($request->user() && $request->routeIs('home', 'login')) {
            return redirect($landing->resolve($request->user())['url']);
        }

        if (! $request->user() && ! $request->routeIs('home', 'login')) {
            return redirect()->route('login');
        }

        if ($request->user() !== null) {
            $this->authorizeInitialPath($request, app(FlowAuthorizationService::class));
        }

        $sessionMinutes = max(1, (int) config('session.flowtrack_idle_lifetime', config('session.flowtrack_absolute_lifetime', 30)));
        $sessionReason = (string) $request->query('session', '');
        $expiresAtTimestamp = (int) $request->session()->get('flowtrack.session_expires_at', 0);
        $expiresAt = $expiresAtTimestamp > 0
            ? Carbon::createFromTimestamp($expiresAtTimestamp)->toIso8601String()
            : null;

        $loginError = $request->session()->get('error')
            ?: $request->session()->get('errors')?->first('email');
        if (! $loginError && $sessionReason === 'expired') {
            $loginError = "Your session expired after {$sessionMinutes} minutes without activity. Please sign in again.";
        } elseif (! $loginError && $sessionReason === 'replaced') {
            $loginError = 'This account was signed in from another browser or device. Your previous session has been logged out.';
        }

        $viewData = [
            'flowtrackAuthenticated' => $request->user() !== null,
            'flowtrackSessionReason' => $sessionReason,
            'flowtrackSessionMinutes' => $sessionMinutes,
            'flowtrackSessionExpiresAt' => $expiresAt,
            'flowtrackLoginError' => $loginError,
            'flowtrackReplaceSession' => (bool) $request->session()->get('flowtrack_replace_session', false),
            'flowtrackSessionConfig' => [
                'minutes' => $sessionMinutes,
                'expiresAt' => $expiresAt,
                'statusEndpoint' => route('flowtrack.session.status'),
                'expiredEndpoint' => route('flowtrack.session.expired'),
            ],
            'flowtrackPusherConfig' => [
                'enabled' => $request->user() !== null && filled(config('services.pusher.key')),
                'key' => config('services.pusher.key'),
                'cluster' => config('services.pusher.cluster', 'ap2'),
                'authEndpoint' => route('flowtrack.pusher.auth'),
                'userId' => $request->user()?->id,
                'pollIntervalMs' => (int) config('flowtrack.realtime_poll_interval_ms', 5000),
            ],
            'flowtrackInitialPath' => '/'.ltrim($request->path(), '/'),
            'flowtrackBranding' => $branding->forRequest($request),
        ];

        return response()
            ->view('flowtrack-template', $viewData)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
