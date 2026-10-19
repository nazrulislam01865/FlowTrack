<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MentionService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaggedCommentIdentityDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_preview_replaces_stored_mention_handle_with_display_name(): void
    {
        $mentioned = User::factory()->create([
            'name' => 'Ying Zhang',
            'is_active' => true,
        ]);

        $handle = app(MentionService::class)->handle($mentioned);

        $this->assertSame(
            'Please review @Ying Zhang today',
            app(MentionService::class)->displayText('Please review @'.$handle.' today'),
        );
    }

    public function test_notifications_keep_the_actor_id_for_profile_photo_rendering(): void
    {
        $actor = User::factory()->create(['name' => 'Amy Actor', 'is_active' => true]);
        $recipient = User::factory()->create(['is_active' => true]);

        $notification = app(NotificationService::class)->notifyUser(
            $recipient,
            'Amy Actor mentioned you in an item',
            'Please review this',
            'mention',
            null,
            null,
            $actor,
        );

        $this->assertNotNull($notification);
        $this->assertSame($actor->id, (int) $notification->actor_id);
        $this->assertSame($actor->id, $notification->actor?->id);
    }

    public function test_tagged_comments_use_actor_avatar_and_humanized_mention_text(): void
    {
        $view = file_get_contents(resource_path('views/livewire/dashboard/tagged-comments.blade.php'));
        $dashboard = file_get_contents(app_path('Services/LegacyDashboardService.php'));
        $notification = file_get_contents(app_path('Models/FlowNotification.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_12_063000_add_actor_to_flow_notifications.php'));

        $this->assertStringContainsString('<x-ui.avatar class="ft-mgmt-mention-avatar"', $view);
        $this->assertStringContainsString('MentionService::class)->displayText($mention->message)', $view);
        $this->assertStringContainsString("'actor:id,name,profile_image_path'", $dashboard);
        $this->assertStringContainsString('FlowNotification::supportsActorIdentity()', $dashboard);
        $this->assertStringContainsString('hydrateLegacyMentionActors', $dashboard);
        $this->assertStringContainsString("belongsTo(User::class, 'actor_id')", $notification);
        $this->assertStringContainsString('supportsActorIdentity', $notification);
        $this->assertStringContainsString("foreignId('actor_id')", $migration);
    }
}
