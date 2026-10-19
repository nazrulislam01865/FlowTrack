@extends('layouts.app')
@section('content')
{{-- The Task Pack list already lazy-loads records with wire:init="loadTaskPacks". --}}
<livewire:task-pack-setup.index />
@endsection
