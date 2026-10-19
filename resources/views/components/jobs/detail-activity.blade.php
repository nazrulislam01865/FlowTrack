@props(['job','compact'=>false,'mentionUsers'=>collect(),'activityTab'=>'all','activityPage'=>1,'focusComment'=>null,'canComment'=>null])
@php
    $canComment = $canComment === null ? false : (bool) $canComment;
    // JobService already applies the selected activity filter and database
    // pagination. Keeping only the visible page here prevents large Orders
    // from hydrating their complete activity history on every render.
    $activities = $job->activities->values();
    $activityPerPage = max(1, (int) ($job->activity_per_page ?? 10));
    $activityTotal = max(0, (int) ($job->activity_total_count ?? $activities->count()));
    $activityPages = max(1, (int) ceil($activityTotal / $activityPerPage));
    $activityCurrentPage = min(max(1, (int) ($job->activity_current_page ?? $activityPage)), $activityPages);
@endphp
<section class="ft-detail-card ft-activity-card ft-friendly-activity {{ $compact ? 'compact' : '' }}">
    <div class="ft-activity-head">
        <div>
            <h2>Activity</h2>
            <p>Comments and Order changes, with who changed what and when.</p>
        </div>
        <div class="ft-activity-tabs">
            <button type="button" class="{{ $activityTab==='all'?'active':'' }}" wire:click="setJobActivityTab('all')">All</button>
            <button type="button" class="{{ $activityTab==='comments'?'active':'' }}" wire:click="setJobActivityTab('comments')">Comments</button>
            <button type="button" class="{{ $activityTab==='history'?'active':'' }}" wire:click="setJobActivityTab('history')">History</button>
        </div>
    </div>
    @if($canComment)
        <div class="ft-comment-composer ft-friendly-composer ft-rich-comment-composer">
            <x-ui.avatar :user="auth()->user()" :name="auth()->user()->name" :size="32"/>
            <textarea class="ft-mention-input" data-rich-text data-rich-text-compact wire:model="jobComment" rows="2" autocomplete="off" data-mention-users="{{ $mentionUsers->toJson() }}" placeholder="Write a comment. Type @ to mention someone or paste a screenshot..."></textarea>
            <button class="ft-new-job-btn" data-rich-text-submit type="button" wire:click="addJobComment" wire:loading.attr="disabled" wire:target="addJobComment">Comment</button>
        </div>
    @endif
    <div class="ft-activity-feed">
        @forelse($activities as $activity)
            @php
                $isComment = $activity->event === 'job.comment';
                $isArtworkRevision = $activity->event === 'job.artwork_revision_requested';
                $actorName = $activity->user?->name ?? 'System';
                $eventLabel = $isComment ? 'Comment' : \Illuminate\Support\Str::headline(str_replace(['job.','task.'], '', (string) $activity->event));
            @endphp
            @php
                $activityFocusKey = $isComment ? 'job-'.$activity->id : null;
                $activityAnchor = $isComment ? 'job-comment-'.$activity->id : null;
                $isFocusedComment = $activityFocusKey !== null && $focusComment === $activityFocusKey;
            @endphp
            <article @if($activityAnchor) id="{{ $activityAnchor }}" @endif class="ft-activity-entry {{ $isComment ? 'is-comment' : 'is-history' }} {{ $isFocusedComment ? 'is-focused-comment' : '' }}" @if($isFocusedComment) x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))" @endif>
                <div class="ft-activity-entry-avatar">
                    <x-ui.avatar :user="$activity->user" :name="$actorName" :size="32"/>
                    <span>{{ $isComment ? '💬' : '↻' }}</span>
                </div>
                <div class="ft-activity-entry-content">
                    <div class="ft-activity-entry-head">
                        <div><b>{{ $actorName }}</b><span class="ft-activity-kind {{ $isComment ? 'comment' : 'history' }}">{{ $isComment ? 'Comment' : 'Change' }}</span></div>
                        <time title="{{ \App\Support\UserLocalTime::format($activity->created_at, 'M j, Y g:i A') }}">{{ $activity->created_at?->diffForHumans() }}</time>
                    </div>
                    @if($isArtworkRevision)
                        <x-jobs.order-detail.revision-activity-content :activity="$activity" />
                    @else
                        <div class="ft-rich-text-content"><x-ui.mention-text :text="$activity->description" /></div>
                    @endif
                    <div class="ft-activity-entry-meta"><span>{{ $eventLabel }}</span><span>•</span><span>{{ \App\Support\UserLocalTime::format($activity->created_at, 'M j, Y · g:i A') }}</span></div>
                </div>
            </article>
        @empty
            <div class="empty-state">No {{ $activityTab==='comments' ? 'comments' : ($activityTab==='history' ? 'changes' : 'activity') }} yet.</div>
        @endforelse
    </div>
    @if($activityTotal > $activityPerPage)
        <div class="ft-activity-pagination">
            <span>Showing {{ (($activityCurrentPage - 1) * $activityPerPage) + 1 }}–{{ min($activityCurrentPage * $activityPerPage, $activityTotal) }} of {{ $activityTotal }}</span>
            <div>
                <button type="button" wire:click="setJobActivityPage({{ $activityCurrentPage - 1 }})" @disabled($activityCurrentPage <= 1)>Previous</button>
                <span>Page {{ $activityCurrentPage }} of {{ $activityPages }}</span>
                <button type="button" wire:click="setJobActivityPage({{ $activityCurrentPage + 1 }})" @disabled($activityCurrentPage >= $activityPages)>Next</button>
            </div>
        </div>
    @endif
</section>
