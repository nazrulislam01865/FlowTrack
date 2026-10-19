<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailOwnerInstantSyncTest extends TestCase
{
    public function test_header_and_planning_owner_controls_share_the_same_success_event(): void
    {
        $header = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));

        foreach ([$header, $planning] as $source) {
            $this->assertStringContainsString('async saveOwner(detail)', $source);
            $this->assertStringContainsString('syncOwner(detail)', $source);
            $this->assertStringContainsString("new CustomEvent('ft-order-owner-updated'", $source);
            $this->assertStringContainsString('x-on:ft-order-owner-updated.window="syncOwner($event.detail)"', $source);
            $this->assertStringContainsString('x-on:ft-inline-remote-selected.stop="saveOwner($event.detail)"', $source);
            $this->assertStringContainsString('this.savedValue = nextValue;', $source);
            $this->assertStringContainsString('this.savedDisplay = nextDisplay;', $source);
            $this->assertStringContainsString('this.savedAvatarUrl = nextAvatarUrl;', $source);
        }
    }

    public function test_owner_save_returns_canonical_display_state_for_cross_control_sync(): void
    {
        $source = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderDetail.php'));

        $this->assertStringContainsString("\$result['value'] = \$owner ? (string) \$owner->id : '';", $source);
        $this->assertStringContainsString("\$result['display'] = \$owner?->name ?? 'Unassigned';", $source);
        $this->assertStringContainsString("\$result['avatarUrl'] = \$owner?->profileImageUrl() ?? '';", $source);
    }
}
