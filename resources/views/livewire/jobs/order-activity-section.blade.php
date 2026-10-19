<div wire:key="order-activity-workspace-{{ $orderId }}">
    @if(! $ready)
        <x-ui.progressive-section-loader
            section="activity"
            method="loadActivitySection"
            key-prefix="order-activity-isolated"
            context-type="order"
            :context-id="$orderId"
            queue-group="order-detail-{{ $orderId }}"
            :queue-priority="40"
            :settle-delay="180"
            :rows="4"
            message="Loading activity when needed…"
            root-margin="40px 0px"
        />
    @else
        <x-jobs.order-detail.activity
            :job="$job"
            :mention-users="$mentionUsers"
            :activity-tab="$jobActivityTab"
            :activity-page="$jobActivityPage"
            :focus-comment="$focusComment"
            :can-comment="$canComment"
        />
    @endif
</div>
