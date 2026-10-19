<div>
@if($showCreate)
    @include('livewire.clients.sections.create')
@elseif($showDetail && $detail)
    @include('livewire.clients.sections.detail')
@else
    @include('livewire.clients.sections.list')
@endif
</div>
