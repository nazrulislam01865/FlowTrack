@props(['tag' => 'section'])
@if($tag === 'div')
<div {{ $attributes }}>{{ $slot }}</div>
@else
<section {{ $attributes }}>{{ $slot }}</section>
@endif
