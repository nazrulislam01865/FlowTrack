<div wire:key="order-attachments-workspace-{{ $orderId }}" data-flowtrack-order-section="attachments" data-flowtrack-section-ready="{{ $ready ? '1' : '0' }}">
    @if(! $ready)
        <x-ui.progressive-section-loader
            section="attachments"
            method="loadAttachmentsSection"
            key-prefix="order-attachments-isolated"
            context-type="order"
            :context-id="$orderId"
            queue-group="order-detail-{{ $orderId }}"
            :queue-priority="30"
            :settle-delay="180"
            :rows="3"
            message="Loading attachments when needed…"
            root-margin="80px 0px"
        />
    @else
        <x-jobs.order-detail.attachments
            :job="$job"
            :context="$context"
            :job-document-uploads="$jobDocumentUploads"
        />
    @endif
</div>
