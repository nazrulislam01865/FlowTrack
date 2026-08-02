<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowTrackBladeSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_served_by_blade(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('FlowTrack')
            ->assertSee('data-livewire-workspace-shell', false)
            ->assertSee('data-livewire-screen="dashboard"', false);
    }


    public function test_login_page_matches_reference_without_microsoft_or_sso_controls(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Plan the work. Track every job.')
            ->assertSee('Deliver with confidence.')
            ->assertSee('Work email')
            ->assertSee('Remember me')
            ->assertSee('Forgot password?')
            ->assertDontSee('Continue with Microsoft')
            ->assertDontSee('Use company SSO');
    }

    public function test_login_credentials_use_a_native_non_cached_form(): void
    {
        $response = $this->get('/login')
            ->assertOk()
            ->assertSee('data-native-secure-login', false)
            ->assertSee('autocomplete="off"', false)
            ->assertDontSee('loginPasswordToggle', false);

        // Symfony normalizes and alphabetizes Cache-Control directives. Test
        // the required protections instead of depending on header ordering.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $source = file_get_contents(resource_path('js/flowtrack/flowtrack-dynamic-source.js'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString("api('/login'", $source);
        $this->assertStringNotContainsString("loginForm?.addEventListener('submit'", $source);
        $this->assertStringNotContainsString('/flowtrack/csrf-token', $source);
        $this->assertStringNotContainsString('X-CSRF-TOKEN', $source);
        $this->assertStringContainsString('X-XSRF-TOKEN', $source);
    }

    public function test_login_requires_confirmation_before_replacing_a_fresh_active_session(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $user = User::query()->where('email', 'manager@flowtrack.cn')->firstOrFail();
        $user->forceFill([
            'active_session_token' => str_repeat('a', 64),
            'active_session_started_at' => now(),
        ])->saveQuietly();

        $this->postJson('/login', [
            'email' => 'manager@flowtrack.cn',
            'password' => 'demo123',
            'remember' => false,
        ])->assertStatus(409)->assertJson([
            'active_session' => true,
            'requires_session_replacement' => true,
        ]);

        $this->assertGuest();

        $this->postJson('/login', [
            'email' => 'manager@flowtrack.cn',
            'password' => 'demo123',
            'remember' => false,
            'replace_session' => true,
        ])->assertOk()->assertJson([
            'replaced_existing_session' => true,
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame(str_repeat('a', 64), $user->fresh()->active_session_token);
    }

    public function test_authenticated_home_redirects_to_first_allowed_dashboard_page(): void
    {
        $this->seed(FlowTrackSeeder::class);

        $user = User::query()->where('email', 'manager@flowtrack.cn')->firstOrFail();

        $this->actingAs($user)->get('/')->assertRedirect('/flowtrack/view/dashboard');
    }
}
