<?php

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use App\Notifications\FlowTrackAlert;
use Database\Seeders\FlowTrack\FlowTrackSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(FlowTrackSeeder::class);
});

test('an authority account receives a workspace-wide copy without changing the original recipients read state', function () {
    $authority = User::where('email', 'manager@flowtrack.cn')->firstOrFail();
    $recipient = User::where('email', 'tang.rui@flowtrack.cn')->firstOrFail();
    $eventId = (string) Str::uuid();

    $recipient->notify(new FlowTrackAlert([
        'event_id' => $eventId,
        'workspace_id' => $authority->currentWorkspaceId(),
        'event' => 'task.assigned',
        'title' => 'Authority feed test',
        'text' => 'A notification intended for a specific workspace user.',
        'type' => 'task',
        'url' => '/flowtrack/my-work',
    ]));

    $feed = app(NotificationFeedService::class);
    $feed->synchronizeAuthorityFeed($authority);

    $authorityCopy = $authority->notifications()
        ->get()
        ->first(fn ($notification) => data_get($notification->data, 'event_id') === $eventId);
    $recipientCopy = $recipient->notifications()
        ->get()
        ->first(fn ($notification) => data_get($notification->data, 'event_id') === $eventId);

    expect($authorityCopy)->not->toBeNull()
        ->and($recipientCopy)->not->toBeNull()
        ->and($authorityCopy->id)->not->toBe($recipientCopy->id)
        ->and($authorityCopy->read_at)->toBeNull()
        ->and($recipientCopy->read_at)->toBeNull();

    $this->actingAs($authority)
        ->patchJson(route('flowtrack.notifications.read', $authorityCopy))
        ->assertOk()
        ->assertJsonPath('unread_count', fn (int $count) => $count >= 0);

    expect($authorityCopy->fresh()->read_at)->not->toBeNull()
        ->and($recipientCopy->fresh()->read_at)->toBeNull();
});

test('a normal user only receives notifications addressed to that user', function () {
    $viewer = User::where('email', 'tang.rui@flowtrack.cn')->firstOrFail();
    $other = User::where('email', 'gao.yu@flowtrack.cn')->firstOrFail();
    $eventId = (string) Str::uuid();

    $other->notify(new FlowTrackAlert([
        'event_id' => $eventId,
        'workspace_id' => $viewer->currentWorkspaceId(),
        'event' => 'task.assigned',
        'title' => 'Private recipient test',
        'text' => 'This notification belongs to another user.',
        'type' => 'task',
    ]));

    $feed = app(NotificationFeedService::class);

    expect($feed->isAuthority($viewer))->toBeFalse()
        ->and($feed->latest($viewer)->contains(
            fn ($notification) => data_get($notification->data, 'event_id') === $eventId,
        ))->toBeFalse();
});

test('notification status returns the authority unread count and a latest signature', function () {
    $authority = User::where('email', 'manager@flowtrack.cn')->firstOrFail();

    $this->actingAs($authority)
        ->getJson(route('flowtrack.notifications.status'))
        ->assertOk()
        ->assertJsonStructure(['unread_count', 'latest_signature', 'authority_view'])
        ->assertJsonPath('authority_view', true);
});
