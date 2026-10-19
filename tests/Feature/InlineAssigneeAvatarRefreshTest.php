<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class InlineAssigneeAvatarRefreshTest extends TestCase
{
    public function test_user_filter_options_include_profile_image_urls_for_inline_assignee_picker(): void
    {
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));

        $this->assertStringContainsString("'profile_image_path'", $service);
        $this->assertStringContainsString("'avatarUrl' => \$row->profileImageUrl()", $service);
    }

    public function test_assignee_inline_actions_return_the_confirmed_avatar_url(): void
    {
        $jobs = OrderPhase5Source::livewire();
        $inquiries = $this->inquiryLivewireSource();

        $this->assertStringContainsString('$result[\'avatarUrl\'] = $assignee?->profileImageUrl()', $jobs);
        $this->assertStringContainsString('$result[\'avatarUrl\'] = $updatedTask->assignee?->profileImageUrl()', $jobs);
        $this->assertStringContainsString("'avatarUrl' => \$assignee?->profileImageUrl()", $inquiries);
    }

    public function test_inline_runtime_keeps_avatar_state_in_sync_without_a_page_refresh(): void
    {
        $runtime = file_get_contents(resource_path('js/components/inline-edit.js'));
        $picker = file_get_contents(resource_path('views/components/ui/inline-remote-user.blade.php'));
        $liveAvatar = file_get_contents(resource_path('views/components/ui/inline-live-avatar.blade.php'));

        $this->assertStringContainsString('savedAvatarUrl', $runtime);
        $this->assertStringContainsString("Object.prototype.hasOwnProperty.call(response, 'avatarUrl')", $runtime);
        $this->assertStringContainsString("avatarUrl: String(item.avatarUrl || '')", $picker);
        $this->assertStringContainsString(':src="avatarUrl"', $liveAvatar);
    }
}
