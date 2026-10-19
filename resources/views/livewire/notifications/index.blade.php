<div>
<x-ui.page-head title="Notifications" subtitle="Assignments, changes and attention alerts delivered according to your role and record access">
    <x-slot:actions><button class="ghost" wire:click="markAllRead">Mark all read</button></x-slot:actions>
</x-ui.page-head>
<div class="card section-card"><div class="attention-list">
@forelse($notifications as $n)
    @php
        $url = app(\App\Services\NotificationService::class)->urlFor($n);
    @endphp
    <div class="attention-item" style="{{ $n->read_at?'opacity:.62':'' }}">
        <span class="signal {{ $n->type==='risk'?'red':($n->type==='assignment'?'purple':($n->type==='approval'?'amber':'purple')) }}"></span>
        <div><div class="item-title">{{ $n->title }} @if(!$n->read_at)<span class="badge b-blue">New</span>@endif</div><div class="item-meta">{{ app(\App\Services\MentionService::class)->displayText($n->message) }} · {{ $n->created_at->diffForHumans() }}</div></div>
        <a class="mini-btn" href="{{ $url }}">Open</a>
    </div>
@empty
    <div class="empty-state">No notifications.</div>
@endforelse
</div>
@if($notifications->total() > 30)
    <div class="ft-list-pagination ft-notification-pagination">
        <span>Showing <b>{{ $notifications->firstItem() ?? 0 }}–{{ $notifications->lastItem() ?? 0 }}</b> of {{ $notifications->total() }} notifications</span>
        <div class="ft-page-actions">
            <button type="button" wire:click="previousPage('notificationsPage')" @disabled($notifications->onFirstPage())>Previous</button>
            <span>Page {{ $notifications->currentPage() }} of {{ $notifications->lastPage() }}</span>
            <button type="button" wire:click="nextPage('notificationsPage')" @disabled(!$notifications->hasMorePages())>Next</button>
        </div>
    </div>
@endif
</div></div>
</div>
