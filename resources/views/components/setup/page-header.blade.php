@props(['title', 'description' => null, 'wrapActions' => true])
<div class="ft-admin-page-head">
    <div>
        <h1>{{ $title }}</h1>
        @if($description)<p>{{ $description }}</p>@endif
    </div>
    @if(isset($actions))
        @if($wrapActions)<div class="ft-admin-head-actions">{{ $actions }}</div>@else{{ $actions }}@endif
    @endif
</div>
