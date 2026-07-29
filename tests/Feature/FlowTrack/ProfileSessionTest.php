<?php

use App\Models\User;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(FlowTrackSeeder::class);
});

test('an authenticated user opening login is redirected without rendering the login screen', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

test('bootstrap returns the real authenticated profile', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->getJson(route('flowtrack.template-api.bootstrap'))
        ->assertOk()
        ->assertJsonPath('currentUser.id', $manager->id)
        ->assertJsonPath('currentUser.name', $manager->name)
        ->assertJsonPath('currentUser.email', $manager->email)
        ->assertJsonStructure(['currentUser' => [
            'phone', 'locale', 'defaultLandingPage', 'defaultTaskView',
            'avatarUrl', 'notificationPreferences', 'role', 'department',
        ]]);
});

test('a user can update the exact-template profile and preferences', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->patchJson(route('flowtrack.template-api.profile.update'), [
            'name' => 'Li Wei Updated',
            'email' => 'li.wei.updated@flowtrack.cn',
            'phone' => '+8801700000000',
            'locale' => 'en',
            'default_landing_page' => 'mywork',
            'default_task_view' => 'board',
            'notification_preferences' => [
                'task_assignments' => true,
                'due_date_reminders' => false,
                'mentions_comments' => true,
                'workflow_updates' => true,
                'shipment_alerts' => false,
                'payment_reminders' => true,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('currentUser.name', 'Li Wei Updated')
        ->assertJsonPath('currentUser.email', 'li.wei.updated@flowtrack.cn')
        ->assertJsonPath('currentUser.defaultLandingPage', 'mywork')
        ->assertJsonPath('currentUser.defaultTaskView', 'board');

    $this->assertDatabaseHas('users', [
        'id' => $manager->id,
        'name' => 'Li Wei Updated',
        'email' => 'li.wei.updated@flowtrack.cn',
        'phone' => '+8801700000000',
        'default_landing_page' => 'mywork',
        'default_task_view' => 'board',
    ]);
});

test('a user can change password with the current password', function () {
    $manager = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($manager)
        ->putJson(route('flowtrack.template-api.profile.password.update'), [
            'current_password' => 'demo123',
            'password' => 'NewFlowTrackPassword123!',
            'password_confirmation' => 'NewFlowTrackPassword123!',
        ])
        ->assertOk();

    expect(Hash::check('NewFlowTrackPassword123!', $manager->fresh()->password))->toBeTrue();
});
