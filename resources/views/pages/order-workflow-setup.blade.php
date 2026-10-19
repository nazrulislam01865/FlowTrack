@extends('layouts.app')
@section('content')
{{-- Workflow configuration is an interactive editor; keep the shell and form state in one initial mount. --}}
<livewire:order-workflow-setup.index />
@endsection
