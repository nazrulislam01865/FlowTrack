@props(['stage', 'index'])
@php
    $documentCount = collect($stage['tasks'] ?? [])->where('document_enabled', true)->count();
@endphp
<div class="ft-order-workflow-stage-tile" style="--stage: {{ $stage['color'] }}">
    <span>STAGE {{ $index + 1 }}</span>
    <b>{{ $stage['name'] }}</b>
    <small>
        {{ count($stage['tasks'] ?? []) }} tasks
        @if($documentCount) · 📎 {{ $documentCount }} document {{ \Illuminate\Support\Str::plural('task', $documentCount) }} @endif
    </small>
</div>
