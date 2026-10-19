@props([
    'title',
    'message',
    'icon' => 'info',
    'tone' => 'info',
    'class' => '',
])

<section class="ft-tracking-status-strip is-{{ $tone }} {{ $class }}">
    <span class="ft-tracking-status-strip-icon"><x-order-tracking.icon :name="$icon" :size="24" /></span>
    <div>
        <strong>{{ $title }}</strong>
        <p>{{ $message }}</p>
    </div>
</section>
