@props(['job','mentionUsers'=>collect(),'activityTab'=>'all','activityPage'=>1,'focusComment'=>null,'canComment'=>false])
@php
    $activities = $job->activities->values();
    $activityPerPage = max(1, (int) ($job->activity_per_page ?? 10));
    $activityTotal = max(0, (int) ($job->activity_total_count ?? $activities->count()));
    $activityPages = max(1, (int) ceil($activityTotal / $activityPerPage));
    $activityCurrentPage = min(max(1, (int) ($job->activity_current_page ?? $activityPage)), $activityPages);
@endphp
<section class="section-card activity-wide ft-order-section-card" id="billingSection" x-data="{ open:true }">
    <div class="section-head ft-order-section-head">
        <div><h2>Activity</h2><div class="card-sub">Comments, ownership changes, flags, cancellations, holds and workflow history.</div></div>
        <div class="activity-head-actions">
            <div class="page-tabs activity-tabs-inline">
                <button type="button" class="page-tab {{ $activityTab==='all'?'active':'' }}" wire:click="setJobActivityTab('all')">All</button>
                <button type="button" class="page-tab {{ $activityTab==='comments'?'active':'' }}" wire:click="setJobActivityTab('comments')">Comments</button>
                <button type="button" class="page-tab {{ $activityTab==='history'?'active':'' }}" wire:click="setJobActivityTab('history')">History</button>
            </div>
            <button type="button" class="section-toggle ft-order-compact-toggle" x-on:click="open=!open" x-text="open ? 'Hide activity' : 'Show activity'">Hide activity</button>
        </div>
    </div>
    <div class="collapse-body" x-show="open">
        @if($canComment)
            <div class="activity-composer">
                <div class="avatar">{{ collect(preg_split('/\s+/', trim((string) auth()->user()?->name)))->filter()->map(fn($p)=>mb_strtoupper(mb_substr($p,0,1)))->take(2)->implode('') ?: 'SP' }}</div>
                <div class="composer-box"><div class="composer-tools">B &nbsp;&nbsp; <i>I</i> &nbsp;&nbsp; <u>U</u> &nbsp;&nbsp; • List &nbsp;&nbsp; 1. List</div><textarea class="ft-mention-input" data-rich-text data-rich-text-compact wire:model="jobComment" rows="2" autocomplete="off" data-mention-users="{{ $mentionUsers->toJson() }}" placeholder="Write a comment. Type @ to mention someone or paste a screenshot..."></textarea></div>
                <button class="btn primary ft-order-comment-submit" data-rich-text-submit type="button" wire:click="addJobComment" wire:loading.attr="disabled" wire:target="addJobComment">Comment</button>
            </div>
        @endif

        <div class="wide-activity-list">
            @forelse($activities as $activity)
                @php
                    $isComment = $activity->event === 'job.comment';
                    $isCancellation = $activity->event === 'job.cancelled';
                    $isArtworkRevision = $activity->event === 'job.artwork_revision_requested';
                    $isHoldStarted = $activity->event === 'job.hold_started';
                    $isHoldReleased = $activity->event === 'job.hold_released';
                    $isHoldActivity = $isHoldStarted || $isHoldReleased;
                    $customerComment = trim((string) data_get($activity->meta, 'customer_comment', ''));
                    $isArtworkCustomerComment = $customerComment !== '' && in_array((string) $activity->event, [
                        'job.artwork_emailed_to_order_team',
                        'job.workflow_email_skipped',
                    ], true);
                    $actorName = $activity->user?->name ?? 'System';
                    $actorInitials = collect(preg_split('/\s+/', trim($actorName)))->filter()->map(fn($p)=>mb_strtoupper(mb_substr($p,0,1)))->take(2)->implode('');
                    $activityFocusKey = $isComment ? 'job-'.$activity->id : null;
                    $activityAnchor = $isComment ? 'job-comment-'.$activity->id : null;
                    $isFocusedComment = $activityFocusKey !== null && $focusComment === $activityFocusKey;
                @endphp
                <div @if($activityAnchor) id="{{ $activityAnchor }}" @endif class="wide-activity {{ $isFocusedComment ? 'is-focused-comment' : '' }}" @if($isFocusedComment) x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))" @endif>
                    <div class="avatar">{{ $actorInitials ?: 'SP' }}</div>
                    <div>
                        <b>
                            {{ $actorName }}
                            <span class="card-sub activity-kind {{ $isArtworkCustomerComment ? 'is-customer-comment' : '' }} {{ $isHoldStarted ? 'is-order-hold' : '' }} {{ $isHoldReleased ? 'is-order-hold-released' : '' }}">
                                {{ $isHoldReleased ? 'UNHELD' : ($isHoldStarted ? 'HOLD' : ($isArtworkCustomerComment ? 'CUSTOMER COMMENT' : ($isComment ? 'COMMENT' : 'CHANGE'))) }}
                            </span>
                        </b>
                        @if($isHoldActivity)
                            <x-jobs.order-detail.hold-activity-content :activity="$activity" />
                        @elseif($isArtworkCustomerComment)
                            <div class="ft-order-customer-comment-activity">
                                <div class="ft-order-customer-comment-activity__label">Comment sent with artwork</div>
                                <div class="ft-order-customer-comment-activity__copy"><x-ui.mention-text :text="$customerComment" /></div>
                                <div class="ft-order-customer-comment-activity__context">{{ $activity->event === 'job.workflow_email_skipped' ? 'Recorded for manual customer handoff' : 'Sent with customer artwork handoff' }}</div>
                            </div>
                        @else
                            <div class="wide-activity-copy {{ $isCancellation ? 'ft-rich-text-content ft-order-cancellation-activity-copy' : '' }}">@if($isArtworkRevision)<x-jobs.order-detail.revision-activity-content :activity="$activity" />@else<x-ui.mention-text :text="$activity->description" />@endif</div>
                        @endif
                        @unless($isHoldActivity)
                            <div class="card-sub">{{ \Illuminate\Support\Str::headline(str_replace(['job.','task.'], '', (string) $activity->event)) }}</div>
                        @endunless
                    </div>
                    <time title="{{ \App\Support\UserLocalTime::format($activity->created_at, 'M j, Y g:i A') }}">{{ $activity->created_at?->diffForHumans() }}</time>
                </div>
            @empty
                <div class="empty-stage">No {{ $activityTab==='comments' ? 'comments' : ($activityTab==='history' ? 'history' : 'activity') }} yet.</div>
            @endforelse
        </div>

        @if($activityTotal > $activityPerPage)
            <div class="activity-pagination"><span>Showing {{ (($activityCurrentPage - 1) * $activityPerPage) + 1 }}–{{ min($activityCurrentPage * $activityPerPage, $activityTotal) }} of {{ $activityTotal }}</span><div><button type="button" class="btn small" wire:click="setJobActivityPage({{ $activityCurrentPage - 1 }})" @disabled($activityCurrentPage <= 1)>←</button><span>Page {{ $activityCurrentPage }} of {{ $activityPages }}</span><button type="button" class="btn small" wire:click="setJobActivityPage({{ $activityCurrentPage + 1 }})" @disabled($activityCurrentPage >= $activityPages)>→</button></div></div>
        @endif
    </div>
</section>
