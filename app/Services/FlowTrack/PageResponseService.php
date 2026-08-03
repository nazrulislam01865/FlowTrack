<?php

namespace App\Services\FlowTrack;

use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\WorkspaceBrandingService;
use App\Support\FlowTrackPage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

final class PageResponseService
{
    public function __construct(
        private readonly WorkspaceBrandingService $branding,
        private readonly FlowAuthorizationService $authorization,
    ) {}

    /**
     * @param array{0:string,1:string}|Closure|null $requirement
     */
    public function render(Request $request, string $page, array|Closure|null $requirement = null): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ($requirement !== null) {
            $allowed = $requirement instanceof Closure
                ? (bool) $requirement($user, $this->authorization)
                : $this->authorization->allows($user, $requirement[0], $requirement[1]);

            abort_unless($allowed, 403);
        }

        return $this->response($request, [
            'flowtrackAuthenticated' => true,
            'flowtrackPage' => $page,
            'flowtrackPageRoutes' => FlowTrackPage::paths(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    public function response(Request $request, array $extra = []): Response
    {
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

        $data = [
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
                'csrfToken' => csrf_token(),
                'pollIntervalMs' => (int) config('flowtrack.realtime_poll_interval_ms', 5000),
            ],
            'flowtrackInitialPath' => '/'.ltrim($request->path(), '/'),
            'flowtrackBranding' => $this->branding->forRequest($request),
            'flowtrackPage' => FlowTrackPage::fromRoute($request->route()?->getName()),
            'flowtrackPageRoutes' => FlowTrackPage::paths(),
        ];

        return response()
            ->view('flowtrack-template', array_replace($data, $extra))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
