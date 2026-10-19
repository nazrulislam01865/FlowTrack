@extends('layouts.app')
@section('content')
@if(request()->boolean('create'))
    {{-- Create Client reads ?create=1 during mount(), so do not defer this mode. --}}
    <livewire:clients.index />
@else
    <livewire:clients.index defer />
@endif
@endsection
