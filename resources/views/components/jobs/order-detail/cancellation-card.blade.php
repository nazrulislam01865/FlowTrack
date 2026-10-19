@props([
    'job',
    'stageName' => null,
])

@php
    $richText = app(\App\Services\RichTextService::class);
    $reason = trim((string) ($job->cancellation_reason ?? ''));
    $plainReason = $richText->plainText($reason);
    $safeHtml = $richText->safeHtml($reason);
    $imageCount = $safeHtml ? preg_match_all('/<img\b/i', $safeHtml) : 0;

    $previewText = trim((string) preg_replace('/\s*\[Image\]\s*/i', ' ', $plainReason));
    $previewText = trim((string) preg_replace('/\s+/', ' ', $previewText));

    if ($previewText === '') {
        $previewText = $imageCount > 0
            ? 'Cancellation evidence was attached.'
            : 'No written cancellation reason was recorded.';
    }

    $previewText = \Illuminate\Support\Str::limit($previewText, 165);
    $cancelledAt = $job->cancelled_at ?: $job->updated_at;
    $cancelledByName = trim((string) ($job->cancelledBy?->name ?? 'System')) ?: 'System';
    $cancelledByInitial = mb_strtoupper(mb_substr($cancelledByName, 0, 1));
    $resolvedStageName = trim((string) ($stageName ?: $job->phase?->name ?: '—'));
@endphp

<section
    class="ft-order-cancellation-card"
    x-data="{ expanded: false }"
    aria-label="Order cancellation details"
>
    <div class="ft-order-cancellation-summary">
        <div class="ft-order-cancellation-icon" aria-hidden="true">⊘</div>

        <div class="ft-order-cancellation-main">
            <div class="ft-order-cancellation-heading-row">
                <div>
                    <div class="ft-order-cancellation-eyebrow">Cancellation record</div>
                    <h2>Order cancelled</h2>
                </div>

                <span class="ft-order-cancellation-status">Workflow locked</span>
            </div>

            <p class="ft-order-cancellation-preview">{{ $previewText }}</p>

            <div class="ft-order-cancellation-meta" aria-label="Cancellation metadata">
                <span class="ft-order-cancellation-meta-item">
                    <span class="ft-order-cancellation-avatar" aria-hidden="true">{{ $cancelledByInitial }}</span>
                    <span>
                        <small>Cancelled by</small>
                        <strong>{{ $cancelledByName }}</strong>
                    </span>
                </span>

                <span class="ft-order-cancellation-meta-item">
                    <span class="ft-order-cancellation-meta-icon" aria-hidden="true">◷</span>
                    <span>
                        <small>Cancelled on</small>
                        <strong>{{ $cancelledAt ? \App\Support\UserLocalTime::format($cancelledAt, 'M j, Y · g:i A') : '—' }}</strong>
                    </span>
                </span>

                <span class="ft-order-cancellation-meta-item">
                    <span class="ft-order-cancellation-meta-icon" aria-hidden="true">▣</span>
                    <span>
                        <small>Last stage</small>
                        <strong>{{ $resolvedStageName }}</strong>
                    </span>
                </span>

                @if($imageCount > 0)
                    <span class="ft-order-cancellation-meta-item">
                        <span class="ft-order-cancellation-meta-icon" aria-hidden="true">▧</span>
                        <span>
                            <small>Evidence</small>
                            <strong>{{ $imageCount }} {{ \Illuminate\Support\Str::plural('image', $imageCount) }}</strong>
                        </span>
                    </span>
                @endif
            </div>
        </div>

        <button
            type="button"
            class="ft-order-cancellation-toggle"
            x-on:click="expanded = !expanded"
            x-bind:aria-expanded="expanded ? 'true' : 'false'"
            aria-controls="order-cancellation-details-{{ $job->id }}"
        >
            <span x-text="expanded ? 'Hide details' : 'View details'">View details</span>
            <span class="ft-order-cancellation-chevron" aria-hidden="true" x-bind:class="{ 'is-open': expanded }">⌄</span>
        </button>
    </div>

    <div
        id="order-cancellation-details-{{ $job->id }}"
        class="ft-order-cancellation-details"
        x-cloak
        x-show="expanded"
        x-transition.opacity.duration.150ms
    >
        <div class="ft-order-cancellation-detail-block">
            <div class="ft-order-cancellation-detail-title">
                <span>Reason & evidence</span>
                @if($imageCount > 0)
                    <small>Click an image to enlarge it</small>
                @endif
            </div>

            @if($reason !== '')
                <div class="ft-rich-text-content ft-order-cancellation-rich-content">
                    <x-ui.mention-text :text="$reason" />
                </div>
            @else
                <p class="ft-order-cancellation-empty">No written reason was recorded for this cancellation.</p>
            @endif
        </div>

        <div class="ft-order-cancellation-note">
            <span aria-hidden="true">ⓘ</span>
            <span>The order is retained as history, while workflow progression remains blocked. Use <strong>Initiate Redo</strong> when a controlled replacement order is required.</span>
        </div>
    </div>
</section>
