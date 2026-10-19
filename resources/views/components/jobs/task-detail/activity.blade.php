            <section class="ft-detail-card ft-task-activity-card ft-friendly-activity">
                <div class="ft-activity-head">
                    <div><h2>Activity</h2><p>Comments and task changes, with who changed what and when.</p></div>
                    <div class="ft-activity-tabs"><button type="button" class="{{ $activityTab==='all'?'active':'' }}" wire:click="setTaskActivityTab('all')">All</button><button type="button" class="{{ $activityTab==='comments'?'active':'' }}" wire:click="setTaskActivityTab('comments')">Comments</button><button type="button" class="{{ $activityTab==='history'?'active':'' }}" wire:click="setTaskActivityTab('history')">History</button></div>
                </div>
                @if($canEditTask)
                    <div class="ft-comment-composer ft-friendly-composer ft-rich-comment-composer"><x-ui.avatar :user="auth()->user()" :name="auth()->user()->name" :size="32"/><textarea class="ft-mention-input" data-rich-text data-rich-text-compact wire:model="taskComment" rows="2" autocomplete="off" data-mention-users='@json($mentionUsers->values())' placeholder="Write a comment. Type @ to mention someone or paste a screenshot..."></textarea><button class="ft-new-job-btn" data-rich-text-submit type="button" wire:click="addTaskComment" wire:loading.attr="disabled" wire:target="addTaskComment">Comment</button></div>
                @endif
                <div class="ft-activity-feed">
                    @forelse($timeline as $entry)
                        @php
                            $eventLabel = $entry->kind === 'comment' ? 'Comment' : \Illuminate\Support\Str::headline(str_replace(['task.','job.'], '', (string) $entry->event));
                            $actorName = $entry->user?->name ?? 'System';
                            $entryLocalTime = $entry->created_at?->copy()->timezone($displayTimezone);
                        @endphp
                        @php
                            $entryFocusKey = $entry->kind === 'comment' ? 'task-'.$entry->id : null;
                            $entryAnchor = $entry->kind === 'comment' ? 'task-comment-'.$entry->id : null;
                            $isFocusedComment = $entryFocusKey !== null && $focusComment === $entryFocusKey;
                        @endphp
                        <article @if($entryAnchor) id="{{ $entryAnchor }}" @endif class="ft-activity-entry {{ $entry->kind==='comment' ? 'is-comment' : 'is-history' }} {{ $isFocusedComment ? 'is-focused-comment' : '' }}" @if($isFocusedComment) x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))" @endif>
                            <div class="ft-activity-entry-avatar"><x-ui.avatar :user="$entry->user" :name="$actorName" :size="32"/><span>{{ $entry->kind==='comment' ? '💬' : '↻' }}</span></div>
                            <div class="ft-activity-entry-content">
                                <div class="ft-activity-entry-head"><div><b>{{ $actorName }}</b><span class="ft-activity-kind {{ $entry->kind==='comment' ? 'comment' : 'history' }}">{{ $entry->kind==='comment' ? 'Comment' : 'Change' }}</span></div><div class="ft-activity-entry-actions"><time title="{{ $entryLocalTime?->format('M j, Y g:i A') }} {{ $displayTimezone }}">{{ $entry->created_at?->diffForHumans() }}</time>@if($canModerateTaskActivity && $entry->event !== 'task.moderation_deleted')<button type="button" class="ft-activity-delete-action" wire:click="{{ $entry->kind === 'comment' ? 'deleteTaskComment('.$entry->id.')' : 'deleteTaskActivity('.$entry->id.')' }}" wire:confirm="Delete this {{ $entry->kind === 'comment' ? 'comment/mention' : 'task activity' }}? The deletion itself will remain recorded in activity.">Delete</button>@endif</div></div>
                                <div class="ft-rich-text-content"><x-ui.mention-text :text="$entry->body" /></div>
                                <div class="ft-activity-entry-meta"><span>{{ $eventLabel }}</span><span>•</span><span>{{ $entryLocalTime?->format('M j, Y · g:i A') }}</span></div>
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">No {{ $activityTab==='comments' ? 'comments' : ($activityTab==='history' ? 'changes' : 'activity') }} yet.</div>
                    @endforelse
                </div>
                @if($timelineTotal > $activityPerPage)
                    <div class="ft-activity-pagination">
                        <span>Showing {{ (($timelineCurrentPage - 1) * $activityPerPage) + 1 }}–{{ min($timelineCurrentPage * $activityPerPage, $timelineTotal) }} of {{ $timelineTotal }}</span>
                        <div>
                            <button type="button" wire:click="setTaskActivityPage({{ $timelineCurrentPage - 1 }})" @disabled($timelineCurrentPage <= 1)>Previous</button>
                            <span>Page {{ $timelineCurrentPage }} of {{ $timelinePages }}</span>
                            <button type="button" wire:click="setTaskActivityPage({{ $timelineCurrentPage + 1 }})" @disabled($timelineCurrentPage >= $timelinePages)>Next</button>
                        </div>
                    </div>
                @endif
            </section>
