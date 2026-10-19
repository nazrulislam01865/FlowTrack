@extends('layouts.app')
@section('content')
@if(request()->has('workflow'))
    {{-- The selected workflow is resolved from ?workflow= during mount(). --}}
    <livewire:workflow-setup.index />
@else
    <livewire:workflow-setup.index defer />
@endif
@endsection
