@extends('layouts.app')
@section('content')
{{-- Master Data already performs safe internal progressive loading with wire:init.
     Do not defer the routing/editor component itself. --}}
<livewire:master-data.index />
@endsection
