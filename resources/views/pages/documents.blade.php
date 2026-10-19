@extends('layouts.app')
@section('content')
@if(request()->hasAny(['client', 'job']))
    {{-- Contextual document routes rely on original client/job query parameters. --}}
    <livewire:documents.index />
@else
    <livewire:documents.index defer />
@endif
@endsection
