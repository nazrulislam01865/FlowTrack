<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Actions\Dashboard\MarkDashboardMentionsRead;
use App\Queries\Dashboard\DashboardMentionsQuery;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class TaggedComments extends Component
{
    use RefreshesFromWorkspace;
    public string $filter = 'all';

    #[Reactive]
    public int $rangeDays = 7;

    #[Reactive]
    public string $clientFilter = '';

    #[Reactive]
    public string $teamFilter = '';

    #[Reactive]
    public string $search = '';

    public function placeholder(): string
    {
        return <<<'HTML'
            <section class="ft-panel">
                <div class="ft-panel-head">
                    <h2 class="ft-panel-title">Mentions for you</h2>
                </div>
                <div style="padding:24px">Loading comments...</div>
            </section>
        HTML;
    }

    public function setFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'unread', 'orders', 'inquiries'], true), 422);
        $this->filter = $filter;
    }

    public function markAllRead(): void
    {
        app(MarkDashboardMentionsRead::class)->handle(
            auth()->user(),
            max(0, (int) $this->clientFilter),
            max(0, (int) $this->teamFilter),
            $this->rangeDays,
            $this->search,
        );
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // A normal Livewire re-render is enough. NotificationService clears the
        // recipient's dashboard caches before the browser receives this event.
    }

    public function render()
    {
        $service = app(DashboardMentionsQuery::class);
        $clientId = max(0, (int) $this->clientFilter);
        $departmentId = max(0, (int) $this->teamFilter);

        // Apply both the local mention tab and the parent dashboard controls in SQL
        // before LIMIT. This keeps the feed synchronized with Today/7 days/30 days,
        // Client, Team and Search instead of behaving like an isolated widget.
        $mentions = $service->rows(
            auth()->user(),
            $this->filter,
            4,
            $clientId,
            $departmentId,
            $this->rangeDays,
            $this->search,
        );

        return view('livewire.dashboard.tagged-comments', [
            'mentions' => $mentions,
            'unreadMentionCount' => $service->unreadCount(
                auth()->user(),
                $clientId,
                $departmentId,
                $this->rangeDays,
                $this->search,
            ),
        ]);
    }
}
