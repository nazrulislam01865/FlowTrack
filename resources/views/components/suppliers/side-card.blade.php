@props(['title'])
<section {{ $attributes->class(['ft-supplier-side-card']) }}>
    <h3>{{ $title }}</h3>
    {{ $slot }}
</section>
