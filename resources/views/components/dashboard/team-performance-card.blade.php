@props(['person'])
@php
    $total = (int) $person->total_task_count;
    $open = (int) $person->open_count;
    $completed = (int) $person->completed_count;
    $completionRate = $person->completion_rate;
    $inquiryCount = (int) $person->inquiry_task_count;
    $orderCount = (int) $person->order_task_count;
    $departmentColor = $person->department_color ?? null;
@endphp
<article
    {{ $attributes->class(['ft-mgmt-team-card', 'has-department-color' => filled($departmentColor)]) }}
    style="{{ \App\Support\MasterColor::style($departmentColor) }}"
>
    <div class="ft-mgmt-team-prototype-head">
        <div class="ft-mgmt-person ft-mgmt-team-person">
            <x-ui.avatar :user="$person" :name="$person->name" :size="44" />
            <div class="ft-mgmt-team-person-copy">
                <strong title="{{ $person->name }}">{{ $person->name }}</strong>
                <span>{{ $person->department?->name ?? 'Team member' }}</span>
            </div>
        </div>

        <div class="ft-mgmt-team-head-metric">
            <b>{{ $completionRate === null ? '—' : $completionRate.'%' }}</b>
            <span>Completion rate</span>
        </div>

        <div class="ft-mgmt-team-head-metric ft-mgmt-team-head-metric-last">
            <b aria-hidden="true">&nbsp;</b>
            <span>On-time rate</span>
        </div>
    </div>

    <div class="ft-mgmt-team-source-row">
        <div class="ft-mgmt-team-source-pills">
            <span>Inquiry <b>{{ $inquiryCount }}</b></span>
            <span>Order <b>{{ $orderCount }}</b></span>
        </div>
        <span class="ft-mgmt-team-assigned-count">{{ $total }} {{ $total === 1 ? 'task' : 'tasks' }} assigned</span>
    </div>

    <div class="ft-mgmt-team-divider"></div>

    <div class="ft-mgmt-team-stat-list">
        <div class="ft-mgmt-team-stat ft-mgmt-team-stat-total">
            <span class="ft-mgmt-team-stat-chip">Total tasks</span>
            <b>{{ $total }}</b>
        </div>
        <div class="ft-mgmt-team-stat ft-mgmt-team-stat-open">
            <span class="ft-mgmt-team-stat-chip">Open tasks</span>
            <b>{{ $open }}</b>
        </div>
        <div class="ft-mgmt-team-stat ft-mgmt-team-stat-completed">
            <span class="ft-mgmt-team-stat-chip">Completed tasks</span>
            <b>{{ $completed }}</b>
        </div>
    </div>

    <div class="ft-mgmt-team-divider"></div>

    <div class="ft-mgmt-team-completion-section">
        <div class="ft-mgmt-team-completion-title">
            <span>Completion rate</span>
            <b>{{ $completionRate === null ? '—' : $completionRate.'%' }}</b>
        </div>
        <div class="ft-mgmt-completion-bar" role="progressbar" aria-label="Completion rate" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completionRate ?? 0 }}">
            <span style="width:{{ $completionRate ?? 0 }}%"></span>
        </div>
        @if($completionRate === null)
            <div class="ft-mgmt-team-empty-copy">No assigned tasks in this reporting period.</div>
        @endif
    </div>

    <div class="ft-mgmt-team-divider"></div>

    <div class="ft-mgmt-team-workload-footer">
        <div class="ft-mgmt-team-workload-left">
            <span>Workload</span>
            <b class="ft-mgmt-team-workload-blank" aria-hidden="true"></b>
        </div>
        <span class="ft-mgmt-team-combined-copy">Inquiry and Order tasks combined</span>
    </div>
</article>
