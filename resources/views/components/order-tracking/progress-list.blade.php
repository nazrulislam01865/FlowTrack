@props([
    'title',
    'subtitle',
    'items',
    'tone' => 'success',
    'class' => '',
])

<section class="ft-tracking-progress-card is-{{ $tone }} {{ $class }}">
    <header>
        <h3>{{ $title }}</h3>
        <p>{{ $subtitle }}</p>
    </header>

    <ol class="ft-tracking-progress-list">
        @foreach($items as $item)
            <li class="is-{{ $item['state'] ?? 'upcoming' }}">
                <span class="ft-tracking-progress-marker" aria-hidden="true">
                    @if(($item['state'] ?? null) === 'completed')
                        <x-order-tracking.icon name="check" :size="15" />
                    @endif
                </span>
                <div>
                    <strong>{{ $item['title'] ?? $item['name'] ?? '' }}</strong>
                    <p>{{ $item['message'] ?? $item['description'] ?? '' }}</p>
                </div>
            </li>
        @endforeach
    </ol>
</section>
