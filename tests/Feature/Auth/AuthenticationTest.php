<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.'account|'.strtolower($user->email)), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

test('a replaced login session is logged out with a visible reason', function () {
    $user = User::factory()->create([
        'active_session_token' => 'new-browser-token',
        'active_session_started_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'flowtrack.active_session_token' => 'old-browser-token',
            'flowtrack.session_started_at' => now()->timestamp,
            'flowtrack.session_expires_at' => now()->addMinutes(30)->timestamp,
        ])
        ->get('/dashboard');

    $response->assertRedirect(route('login', ['session' => 'replaced']));
    $this->assertGuest();
});

test('an absolute thirty minute session is expired even after activity', function () {
    $user = User::factory()->create([
        'active_session_token' => 'current-session-token',
        'active_session_started_at' => now()->subMinutes(31),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'flowtrack.active_session_token' => 'current-session-token',
            'flowtrack.session_started_at' => now()->subMinutes(31)->timestamp,
            'flowtrack.session_expires_at' => now()->subMinute()->timestamp,
        ])
        ->get('/dashboard');

    $response->assertRedirect(route('login', ['session' => 'expired']));
    $this->assertGuest();
});
