<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFactoryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_user_matches_flowtrack_schema_defaults_in_memory(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_super_admin);
        $this->assertTrue($user->is_active);
        $this->assertSame('en', $user->locale);
    }
}
