@extends('layouts.app')
@section('content')
@php
    $inquiryHasInitialRouteContext = request()->hasAny(['create', 'open', 'task', 'metric']);
@endphp

@if($inquiryHasInitialRouteContext)
    {{-- Create/detail/deep-linked metric state is read from the original request. --}}
    <livewire:inquiries.index />
@else
    {{-- Plain Inquiry list mode is safe to defer. --}}
    <livewire:inquiries.index defer />
@endif
@endsection
