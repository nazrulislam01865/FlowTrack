@php
    $date = $overrideDate ?? data_get($row, 'stage_due') ?? data_get($row, 'delivery');
@endphp
<div class="owner-delivery">
    <x-ui.avatar :name="data_get($row,'stage_assignee','Unassigned')" :src="data_get($row,'stage_assignee_avatar')" :size="28" />
    <div><b>{{ data_get($row,'stage_assignee') }}</b><small>{{ $dateLabel }} {{ $formatDate($date) }}</small></div>
</div>
