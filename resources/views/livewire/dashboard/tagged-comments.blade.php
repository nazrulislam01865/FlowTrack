<section class="ft-mgmt-panel ft-mgmt-mentions-panel" id="mentions-for-you">
    <div class="ft-mgmt-mentions-head">
        <div class="ft-mgmt-mentions-heading">
            <div class="ft-mgmt-mentions-title-line">
                <h2>Mentions for you</h2>
                <span class="ft-mgmt-mentions-unread-count">{{ $unreadMentionCount }} unread</span>
            </div>
            <p>{{ app(\App\Services\AccessControlService::class)->isAdministrator(auth()->user()) ? 'All mentions across Orders, Tasks and Inquiries' : 'Comments where teammates tagged you in Orders or Inquiries' }}</p>
        </div>
        <button class="ft-mgmt-mentions-mark-read" type="button" wire:click="markAllRead" @disabled($unreadMentionCount === 0)>Mark all as read</button>
    </div>

    <div class="ft-mgmt-mentions-tabs" role="tablist" aria-label="Mention type">
        <button type="button" class="{{ $filter === 'all' ? 'active' : '' }}" wire:click="setFilter('all')">All</button>
        <button type="button" class="{{ $filter === 'unread' ? 'active' : '' }}" wire:click="setFilter('unread')">Unread ({{ $unreadMentionCount }})</button>
        <button type="button" class="{{ $filter === 'orders' ? 'active' : '' }}" wire:click="setFilter('orders')">Orders</button>
        <button type="button" class="{{ $filter === 'inquiries' ? 'active' : '' }}" wire:click="setFilter('inquiries')">Inquiries</button>
    </div>

    <div class="ft-mgmt-mentions-list">
        @forelse($mentions as $mention)
            @php
                $route = app(\App\Services\NotificationService::class)->urlFor($mention);
                $actor = $mention->actor;
                $actorName = trim((string) ($actor?->name ?? ''));

                if ($actorName === '' && preg_match('/^(.*?) mentioned (?:you|a user) in /u', (string) $mention->title, $actorMatch)) {
                    $actorName = trim((string) ($actorMatch[1] ?? ''));
                }
                $actorName = $actorName !== '' ? $actorName : 'FlowTrack';

                $isInquiry = (bool) ($mention->inquiry_id || $mention->inquiry_task_id);
                $reference = $isInquiry
                    ? ($mention->inquiry?->inquiry_number ?: 'Inquiry')
                    : ($mention->job?->displayOrderNumber() ?: 'Order');

                $message = str(app(\App\Services\MentionService::class)->displayText($mention->message))
                    ->squish()
                    ->limit(180)
                    ->toString();
                $escapedMessage = e($message);
                $currentMention = '@'.auth()->user()->name;
                $messageHtml = str_replace(
                    e($currentMention),
                    '<span class="ft-mgmt-mention-user">'.e($currentMention).'</span>',
                    $escapedMessage
                );

                if ($isInquiry) {
                    $contextTitle = $mention->inquiryTask?->title ?: 'Inquiry activity';
                    $contextLabel = 'Inquiry · '.$contextTitle;
                } else {
                    $phaseName = $mention->task?->phase?->short_name
                        ?: $mention->task?->phase?->name
                        ?: $mention->job?->phase?->short_name
                        ?: $mention->job?->phase?->name
                        ?: 'Order';
                    $contextTitle = $mention->task?->title ?: 'Order activity';
                    $contextLabel = $phaseName.' · '.$contextTitle;
                }
            @endphp
            <div class="ft-mgmt-mention-row {{ $mention->read_at ? '' : 'is-unread' }}" wire:key="dashboard-mention-{{ $mention->id }}">
                <span class="ft-mgmt-mention-unread-dot" aria-hidden="true"></span>
                <x-ui.avatar class="ft-mgmt-mention-avatar" :user="$actor" :name="$actorName" :size="42" />
                <div class="ft-mgmt-mention-copy">
                    <strong>{{ $actorName }} {{ $mention->type === 'mention_admin' ? 'mentioned a user in' : 'mentioned you in' }} {{ $reference }}</strong>
                    <p>{!! $messageHtml !== '' ? $messageHtml : 'Mentioned you in a comment.' !!}</p>
                    <small>{{ $contextLabel }}</small>
                </div>
                <time>{{ $mention->created_at?->diffForHumans(short: true) }}</time>
                <a class="ft-mgmt-mention-view" href="{{ $route }}">View</a>
            </div>
        @empty
            <div class="ft-mgmt-mentions-empty">No mentions in this view.</div>
        @endforelse
    </div>
</section>
