@extends('layouts.app')
@section('content')
{{-- Dedicated editor forms mount immediately. Heavy pickers inside the form remain progressively loaded. --}}
<livewire:workflow-setup.form :workflow-id="$workflowId" :source-workflow-id="$sourceWorkflowId" />
@endsection
