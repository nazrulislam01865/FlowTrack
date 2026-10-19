@props(['number' => null, 'title', 'subtitle' => null])
<section {{ $attributes->class(['ft-product-page-section']) }}>
    <div class="ft-product-page-section-heading">
        @if($number)<span class="ft-product-page-section-number">{{ $number }}</span>@endif
        <div>
            <h2>{{ $title }}</h2>
            @if($subtitle)<p>{{ $subtitle }}</p>@endif
        </div>
    </div>
    <div class="ft-product-page-section-body">{{ $slot }}</div>
</section>
