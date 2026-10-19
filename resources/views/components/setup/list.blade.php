@props(['tag' => 'div'])
@if($tag === 'aside')
<aside {{ $attributes }}>{{ $slot }}</aside>
@elseif($tag === 'section')
<section {{ $attributes }}>{{ $slot }}</section>
@else
<div {{ $attributes }}>{{ $slot }}</div>
@endif
