@props(['icon' => '⚙', 'title', 'tone' => 'info'])
<div {{ $attributes->class(['ft-order-workflow-notice', 'is-document' => $tone === 'document']) }}>
    <span class="ft-order-workflow-notice__icon" aria-hidden="true">{{ $icon }}</span>
    <div>
        <b>{{ $title }}</b>
        <p>{{ $slot }}</p>
    </div>
</div>
