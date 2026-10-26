<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_login_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<title>STEP PROMO</title>', false)
            ->assertSee('/images/step-promo/step-promo-logo.webp', false)
            ->assertSee('/images/step-promo/step-promo-icon.webp', false)
            ->assertSee('Your promo journey,')
            ->assertSee('Welcome back')
            ->assertSee('Sign in');
    }

    public function test_login_page_shows_other_device_message(): void
    {
        $this->get(route('login', ['reason' => 'other-device']))
            ->assertOk()
            ->assertSee('Another device logged in with the same user ID and password.');
    }

    public function test_login_page_shows_timeout_message(): void
    {
        config(['session.lifetime' => 30]);

        $this->get(route('login', ['reason' => 'timeout']))
            ->assertOk()
            ->assertSee('Your 30-minute session has expired.');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_dashboard(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
