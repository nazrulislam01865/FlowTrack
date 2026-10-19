@props(['title','subtitle'])
<div class="page-head">
    <div><h1>{{ $title }}</h1><p>{{ $subtitle }}</p></div>
    @if(isset($actions))<div class="actions">{{ $actions }}</div>@endif
</div>
