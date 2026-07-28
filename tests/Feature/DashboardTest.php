<?php

use App\Models\User;

test('dashboard route renders the exact template for guests', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertViewIs('flowtrack-template');
});

test('dashboard route renders the exact template for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewIs('flowtrack-template');
});
