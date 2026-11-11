@extends('layouts.app')

@section('content')
    @if(config('dashboard.progressive_loading_enabled', false))
        <livewire:dashboard.index />
    @else
        <livewire:dashboard.index defer />
    @endif
@endsection
