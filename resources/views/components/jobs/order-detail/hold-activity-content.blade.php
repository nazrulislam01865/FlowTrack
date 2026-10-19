@props(['activity'])
@php
    $isReleased = (string) $activity->event === 'job.hold_released';
    $meta = (array) ($activity->meta ?? []);
    $holdFrom = (string) (data_get($meta, 'hold_from_label') ?: \App\Models\OrderHold::labelFor(data_get($meta, 'hold_from')));
    $sourceName = trim((string) data_get($meta, 'source_name', '')) ?: '—';
    $reason = trim((string) data_get($meta, 'reason', '')) ?: '—';
    $heldBy = trim((string) data_get($meta, 'held_by_name', '')) ?: ($activity->user?->name ?? 'Unknown user');
    $releasedBy = trim((string) data_get($meta, 'released_by_name', '')) ?: ($activity->user?->name ?? 'Unknown user');
    $startedAt = null;
    $endedAt = null;
    try {
        if (data_get($meta, 'started_at')) $startedAt = \Illuminate\Support\Carbon::parse((string) data_get($meta, 'started_at'));
        if (data_get($meta, 'ended_at')) $endedAt = \Illuminate\Support\Carbon::parse((string) data_get($meta, 'ended_at'));
    } catch (\Throwable) {
        $startedAt = null;
        $endedAt = null;
    }
    $duration = trim((string) data_get($meta, 'duration', ''));
@endphp
<div class="ft-order-hold-activity-content">
    <div class="ft-order-hold-activity-title">{{ $isReleased ? 'Order unheld' : 'Order placed on hold' }}</div>
    <div class="ft-order-hold-activity-grid">
        <dl>
            <div><dt>Hold from:</dt><dd>{{ $holdFrom }}</dd></div>
            <div><dt>Name:</dt><dd>{{ $sourceName }}</dd></div>
            @if($reason !== '—')<div><dt>Reason:</dt><dd><x-ui.mention-text :text="$reason" /></dd></div>@endif
        </dl>
        <dl>
            <div><dt>Held by:</dt><dd>{{ $heldBy }}</dd></div>
            <div><dt>Started:</dt><dd>{{ $startedAt ? \App\Support\UserLocalTime::format($startedAt, 'M j, Y \a\t g:i A') : '—' }}</dd></div>
            @if($isReleased)
                <div><dt>Ended:</dt><dd>{{ $endedAt ? \App\Support\UserLocalTime::format($endedAt, 'M j, Y \a\t g:i A') : '—' }}</dd></div>
                <div><dt>Duration:</dt><dd>{{ $duration ?: '—' }}</dd></div>
                <div><dt>Unheld by:</dt><dd>{{ $releasedBy }}</dd></div>
            @endif
        </dl>
    </div>
</div>
