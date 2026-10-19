<?php

namespace App\Livewire\Jobs;

use App\Actions\Orders\AddOrderComment;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\MentionService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Isolated Order Details activity feed.
 *
 * Activity pagination/comments no longer hydrate the 200+ property Jobs\Index
 * component. Only the visible activity page and mention directory live here.
 */
final class OrderActivitySection extends Component
{
    public int $orderId;
    public bool $ready = false;
    public string $jobActivityTab = 'all';
    public int $jobActivityPage = 1;
    public string $jobComment = '';
    public ?string $focusComment = null;

    public function mount(int $orderId, ?string $focusComment = null): void
    {
        $this->orderId = $orderId;
        $this->focusComment = $focusComment;
    }

    public function loadActivitySection(string $section, ?string $contextType = null, ?int $contextId = null): void
    {
        if ($section !== 'activity' || $contextType !== 'order' || (int) $contextId !== $this->orderId) {
            return;
        }

        $this->ready = true;
    }

    public function setJobActivityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all', 'comments', 'history'], true), 422);
        $this->jobActivityTab = $tab;
        $this->jobActivityPage = 1;
    }

    public function setJobActivityPage(int $page): void
    {
        $this->jobActivityPage = max(1, $page);
    }

    public function addJobComment(): void
    {
        $saved = app(AddOrderComment::class)->handle(
            auth()->user(),
            $this->orderId,
            $this->jobComment,
        );

        if (! $saved) {
            return;
        }

        $this->jobComment = '';
        $this->jobActivityPage = 1;
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // Rerender this feed only; the Order Details coordinator stays untouched.
    }

    public function render()
    {
        if (! $this->ready) {
            return view('livewire.jobs.order-activity-section', [
                'job' => null,
                'mentionUsers' => collect(),
                'canComment' => false,
            ]);
        }

        $user = auth()->user();
        $query = app(VisibleOrderQuery::class);
        $job = $query->base($user, $this->orderId);
        $query->loadOverviewActivity($job, $this->jobActivityTab, $this->jobActivityPage, 10);

        $canComment = app(AccessControlService::class)->canEditVisibleJob($user, $job);
        $mentionUsers = $canComment
            ? app(MentionService::class)->optionsForJob($job, $user)
            : collect();

        return view('livewire.jobs.order-activity-section', compact('job', 'mentionUsers', 'canComment'));
    }
}
