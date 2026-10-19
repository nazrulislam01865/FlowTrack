@extends('layouts.app')
@section('content')
{{-- Dedicated editor forms mount immediately; the form already defers its heavy option data internally. --}}
<livewire:task-pack-setup.form :task-pack-id="$taskPackId" />
@endsection
