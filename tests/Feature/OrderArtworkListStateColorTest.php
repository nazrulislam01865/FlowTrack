<?php

namespace Tests\Feature;

use App\Support\OrderArtworkListState;
use Tests\TestCase;

class OrderArtworkListStateColorTest extends TestCase
{
    public function test_artwork_list_state_uses_semantic_colors(): void
    {
        $this->assertSame('#2D8CF0', OrderArtworkListState::state(OrderArtworkListState::ARTWORK_CONFIRMED)['color']);
        $this->assertSame('#8B5CF6', OrderArtworkListState::state(OrderArtworkListState::CLIENT_DECISION)['color']);
        $this->assertSame('#8B5CF6', OrderArtworkListState::state(OrderArtworkListState::CLIENT_APPROVED)['color']);
        $this->assertSame('#EF476F', OrderArtworkListState::state(OrderArtworkListState::REVISION_REQUIRED)['color']);
    }

    public function test_order_list_uses_the_semantic_artwork_state_before_task_pack_fallback(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));
        $job = file_get_contents(app_path('Models/FlowJob.php'));

        $this->assertStringContainsString('OrderArtworkListState::resolve', $service);
        $this->assertStringContainsString("'artwork_step' =>", $service);
        $this->assertStringContainsString("'latestArtworkRevisionActivity'", $service);
        $this->assertStringContainsString('latestArtworkRevisionActivity(): MorphOne', $job);
        $this->assertStringContainsString("job.artwork_revision_requested", $job);
    }
}
