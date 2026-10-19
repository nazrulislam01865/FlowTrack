<section class="ft-detail-card ft-task-activity-card ft-friendly-activity ft-inquiry-overview-activity-card">
    <div class="ft-activity-head">
        <div><h2>Activity</h2><p>Comments and Inquiry changes, with who changed what and when.</p></div>
        <div class="ft-activity-tabs">
            <button type="button" class="{{ $inquiryActivityTab === 'all' ? 'active' : '' }}" wire:click="setInquiryActivityTab('all')">All</button>
            <button type="button" class="{{ $inquiryActivityTab === 'comments' ? 'active' : '' }}" wire:click="setInquiryActivityTab('comments')">Comments</button>
            <button type="button" class="{{ $inquiryActivityTab === 'history' ? 'active' : '' }}" wire:click="setInquiryActivityTab('history')">History</button>
        </div>
    </div>
    @if($canEditInquiry)
        <div class="ft-comment-composer ft-friendly-composer ft-rich-comment-composer">
            <x-ui.avatar :user="auth()->user()" :name="auth()->user()->name" :size="32"/>
            <textarea class="ft-mention-input" data-rich-text data-rich-text-compact wire:model="inquiryComment" rows="2" autocomplete="off" data-mention-users='@json($inquiryMentionUsers->values())' placeholder="Write a comment. Type @ to mention someone or paste a screenshot..."></textarea>
            <button class="ft-new-job-btn" data-rich-text-submit type="button" wire:click="addInquiryComment" wire:loading.attr="disabled" wire:target="addInquiryComment">Comment</button>
        </div>
    @endif
    <div class="ft-activity-feed">
        @forelse($inquiryActivities ?? [] as $activity)
            @php
                $isComment = $activity->event === 'inquiry.comment';
                $actorName = $activity->user?->name ?? 'System';
                $eventLabel = $isComment ? 'Comment' : str($activity->event)->after('inquiry.')->replace('_',' ')->title();
                $activityTaskId = (int) data_get($activity->meta, 'inquiry_task_id', 0);
                $canModerateTaskActivity = $activityTaskId > 0 && app(\App\Services\AccessControlService::class)->isAdministrator(auth()->user()) && $activity->event !== 'inquiry.task_moderation_deleted';
            @endphp
            <article class="ft-activity-entry {{ $isComment ? 'is-comment' : 'is-history' }}" wire:key="inquiry-overview-activity-{{ $activity->id }}">
                <div class="ft-activity-entry-avatar"><x-ui.avatar :user="$activity->user" :name="$actorName" :size="32"/><span>{{ $isComment ? '💬' : '↻' }}</span></div>
                <div class="ft-activity-entry-content">
                    <div class="ft-activity-entry-head"><div><b>{{ $actorName }}</b><span class="ft-activity-kind {{ $isComment ? 'comment' : 'history' }}">{{ $isComment ? 'Comment' : 'Change' }}</span></div><div class="ft-activity-entry-actions"><time>{{ $activity->created_at?->diffForHumans() }}</time>@if($canModerateTaskActivity)<button type="button" class="ft-activity-delete-action" wire:click="deleteInquiryTaskActivity({{ $activity->id }})" wire:confirm="Delete this Inquiry task comment/mention/activity? The deletion itself will remain recorded in activity.">Delete</button>@endif</div></div>
                    <div class="ft-rich-text-content"><x-ui.mention-text :text="$activity->description" /></div>
                    <div class="ft-activity-entry-meta"><span>{{ $eventLabel }}</span><span>•</span><span>{{ $activity->created_at ? \App\Support\UserLocalTime::format($activity->created_at, 'M j, Y · g:i A') : '—' }}</span></div>
                </div>
            </article>
        @empty
            <div class="empty-state">No {{ $inquiryActivityTab === 'comments' ? 'comments' : ($inquiryActivityTab === 'history' ? 'changes' : 'activity') }} yet.</div>
        @endforelse
    </div>
    @if($inquiryActivities && $inquiryActivities->lastPage() > 1)
        <div class="ft-activity-pagination">
            <span>Showing {{ $inquiryActivities->firstItem() ?? 0 }}–{{ $inquiryActivities->lastItem() ?? 0 }} of {{ $inquiryActivities->total() }}</span>
            <div><button type="button" wire:click="previousPage('inquiryActivityPage')" @disabled($inquiryActivities->onFirstPage())>Previous</button><span>Page {{ $inquiryActivities->currentPage() }} of {{ $inquiryActivities->lastPage() }}</span><button type="button" wire:click="nextPage('inquiryActivityPage')" @disabled(!$inquiryActivities->hasMorePages())>Next</button></div>
        </div>
    @endif
</section>
