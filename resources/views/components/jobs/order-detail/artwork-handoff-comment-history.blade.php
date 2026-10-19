@props([
    'history' => [],
    'label' => 'Previous customer comments',
    'compact' => false,
])
@php
    $entries = collect($history)
        ->filter(fn ($entry) => trim((string) data_get($entry, 'comment', '')) !== '')
        ->values();
@endphp

@if($entries->isNotEmpty())
    <details class="ft-artwork-handoff-comment-history {{ $compact ? 'is-compact' : '' }}">
        <summary>
            <span>{{ $label }}</span>
            <span class="ft-artwork-handoff-comment-history__count">{{ $entries->count() }}</span>
        </summary>
        <div class="ft-artwork-handoff-comment-history__list">
            @foreach($entries as $entry)
                @php
                    $createdAt = data_get($entry, 'created_at');
                    $comment = trim((string) data_get($entry, 'comment', ''));
                @endphp
                <article class="ft-artwork-handoff-comment-history__item">
                    <div class="ft-artwork-handoff-comment-history__meta">
                        <strong>{{ $loop->first ? 'Latest comment' : 'Previous comment' }}</strong>
                        @if($createdAt)
                            <span>{{ \App\Support\UserLocalTime::format($createdAt, 'M j, Y, g:i A') }}</span>
                        @endif
                    </div>
                    <p>{{ $comment }}</p>
                </article>
            @endforeach
        </div>
    </details>
@endif
