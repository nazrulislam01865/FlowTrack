<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Workspace;
use App\Models\User;
use App\Policies\InquiryPolicy;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase9WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_context_scopes_workspace_aware_records_before_hydration(): void
    {
        $workspaceA = Workspace::query()->create([
            'name' => 'A', 'slug' => 'a', 'timezone' => 'UTC', 'default_currency' => 'USD', 'is_active' => true,
        ]);
        $workspaceB = Workspace::query()->create([
            'name' => 'B', 'slug' => 'b', 'timezone' => 'UTC', 'default_currency' => 'USD', 'is_active' => true,
        ]);

        $context = app(WorkspaceContext::class);
        $context->set((int) $workspaceA->id);

        $this->assertTrue($context->contains((int) $workspaceA->id));
        $this->assertFalse($context->contains((int) $workspaceB->id));

        $query = $context->scope(Inquiry::query());
        $this->assertStringContainsString('workspace_id', $query->toSql());
        $this->assertSame([(int) $workspaceA->id], $query->getBindings());

        $outside = new Inquiry();
        $outside->id = 999999;
        $outside->workspace_id = $workspaceB->id;
        $actor = new User();
        $actor->id = 123;

        $this->assertFalse(app(InquiryPolicy::class)->view($actor, $outside));
    }
}
