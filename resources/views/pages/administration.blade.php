@extends('layouts.app')
@section('content')
{{-- Administration reads the requested tab from the original URL during mount(). --}}
<livewire:administration.index />
@endsection
