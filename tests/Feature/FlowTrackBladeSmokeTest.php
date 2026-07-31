<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowTrackBladeSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_served_by_blade(): void
    {
        $this->get('/login')->assertOk()->assertSee('FlowTrack');
    }

    public function test_authenticated_home_redirects_to_dashboard(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }
}
