<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReverbRealtimeImplementationTest extends TestCase
{
    public function test_flowtrack_uses_reverb_without_the_external_pusher_cdn(): void
    {
        $composer = file_get_contents(base_path('composer.json'));
        $client = file_get_contents(resource_path('js/core/realtime.js'));
        $bridge = file_get_contents(resource_path('js/core/browser-api.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $runtime = file_get_contents(resource_path('js/app.js'));
        $notificationRuntime = file_get_contents(resource_path('js/features/notifications.js'));

        $this->assertStringContainsString('laravel/reverb', $composer);
        $this->assertStringContainsString('new WebSocket(url)', $client);
        $this->assertStringContainsString("event: 'pusher:subscribe'", $client);
        $this->assertStringContainsString('ReverbChannelService::class', $layout);
        $this->assertStringContainsString('flowtrack-reverb-host', $layout);
        $this->assertStringNotContainsString('https://js.pusher.com', $layout);
        $this->assertStringNotContainsString("from 'pusher-js'", $runtime);
        $this->assertStringContainsString('get client() { return getRealtimeClient(); }', $bridge);
        $this->assertStringContainsString('window.FlowTrack = existing;', $bridge);
    }

    public function test_realtime_jobs_keep_the_existing_dedicated_queue_and_polling_fallback(): void
    {
        $notificationJob = file_get_contents(app_path('Jobs/DeliverRealtimeNotification.php'));
        $workspaceJob = file_get_contents(app_path('Jobs/DeliverRealtimeWorkspaceEvent.php'));
        $notificationRuntime = file_get_contents(resource_path('js/features/notifications.js'));

        $this->assertStringContainsString('ReverbChannelService', $notificationJob);
        $this->assertStringContainsString("config('services.realtime.queue', 'realtime')", $notificationJob);
        $this->assertStringContainsString('ReverbChannelService', $workspaceJob);
        $this->assertStringContainsString('startUnreadFallback();', $notificationRuntime);
    }

    public function test_private_channel_authorization_is_still_user_and_workspace_scoped(): void
    {
        $service = file_get_contents(app_path('Services/ReverbChannelService.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("'private-flowtrack.user.'.\$userId", $service);
        $this->assertStringContainsString("'private-flowtrack.workspace.'.max(1, \$workspaceId)", $service);
        $this->assertStringContainsString("Route::post('/realtime/auth'", $routes);
    }
}
