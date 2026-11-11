@php
    $flowRows = collect($flowDistribution[$flowTab] ?? []);
    $flowMax = max(1, (int) $flowRows->max('count'));
    $flowRows = $flowRows->map(static function (array $row) use ($flowMax): array {
        $count = (int) ($row['count'] ?? 0);
        $scopeText = trim((string) ($row['scope_text'] ?? ''));

        return [
            'label' => (string) ($row['label'] ?? 'Unassigned'),
            'short_label' => (string) ($row['short_label'] ?? $row['label'] ?? 'Unassigned'),
            'color' => $row['color'] ?? null,
            'count' => $count,
            'width' => $count > 0 ? max(2, (int) round(($count / $flowMax) * 100)) : 0,
            'scope_text' => $scopeText,
            'scope_label' => trim((string) ($row['scope_label'] ?? '')),
            'is_mismatch' => (bool) ($row['is_mismatch'] ?? false),
        ];
    })->values();
    $selectedTaskStatusDistribution = $taskStatusDistribution[$taskStatusTab] ?? ['total' => 0, 'rows' => []];
    $statusRows = collect($selectedTaskStatusDistribution['rows'] ?? [])->values();
    $statusTotal = max(0, (int) ($selectedTaskStatusDistribution['total'] ?? 0));
    $cursor = 0.0;
    $gradientSegments = [];
    foreach ($statusRows as $row) {
        $count = max(0, (int) ($row['count'] ?? 0));
        if ($count <= 0 || $statusTotal <= 0) continue;
        $start = $cursor;
        $cursor += ($count / $statusTotal) * 100;
        $color = (string) ($row['color'] ?? '#64748B');
        $gradientSegments[] = $color.' '.$start.'% '.$cursor.'%';
    }
    if ($cursor < 100) $gradientSegments[] = '#edf2f5 '.$cursor.'% 100%';
    $donutBackground = $statusTotal > 0 ? 'conic-gradient('.implode(',', $gradientSegments).')' : '#edf2f5';
@endphp
    <section class="ft-mgmt-dashboard-distribution-shell" data-dashboard-panel="distribution">
    <div class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid ft-mgmt-dashboard-panel-swap" wire:replace wire:key="dashboard-distribution-content">
        <article class="ft-mgmt-panel ft-mgmt-flow-prototype">
            <div class="ft-mgmt-panel-head">
                <div><h2>Work moving through FlowTrack</h2><p>Active records grouped by configured workflow phase. Client-specific phase differences are labelled.</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setFlowTab('orders')" class="ft-mgmt-tab {{ $flowTab === 'orders' ? 'active' : '' }}">Orders</button>
                    <button type="button" wire:click="setFlowTab('inquiries')" class="ft-mgmt-tab {{ $flowTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                </div>
            </div>
            <div class="ft-mgmt-panel-body">
                <div class="ft-mgmt-flow-bars">
                    @forelse($flowRows as $row)
                        <div class="ft-mgmt-flow-row">
                            <div class="ft-mgmt-flow-label-wrap" title="{{ $row['label'] }}{{ $row['scope_text'] !== '' ? ' — '.$row['scope_text'] : '' }}">
                                <span class="ft-mgmt-flow-label">{{ $row['short_label'] ?? $row['label'] }}</span>
                                @if($row['is_mismatch'] && $row['scope_text'] !== '')
                                    <span class="ft-mgmt-flow-scope">{{ $row['scope_text'] }}</span>
                                @endif
                            </div>
                            <div class="ft-mgmt-track">
                                <span class="ft-mgmt-fill ft-phase-fill {{ (int) $row['count'] === 0 ? 'is-empty' : '' }}" style="{{ \App\Support\MasterColor::style($row['color'] ?? null) }}width:{{ $row['width'] }}%"></span>
                            </div>
                            <span class="ft-mgmt-flow-value">{{ $row['count'] }}</span>
                        </div>
                    @empty
                        <div class="ft-mgmt-empty">No active workflow data.</div>
                    @endforelse
                </div>
            </div>
        </article>

        <article class="ft-mgmt-panel">
            <div class="ft-mgmt-panel-head">
                <div><h2>Task status distribution</h2><p>Current {{ $taskStatusTab === 'orders' ? 'Order' : 'Inquiry' }} task status from Master Data</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setTaskStatusTab('orders')" class="ft-mgmt-tab {{ $taskStatusTab === 'orders' ? 'active' : '' }}">Orders</button>
                    <button type="button" wire:click="setTaskStatusTab('inquiries')" class="ft-mgmt-tab {{ $taskStatusTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                </div>
            </div>
            <div class="ft-mgmt-panel-body ft-mgmt-status-layout">
                <div class="ft-mgmt-donut" style="background:{{ $donutBackground }}"><div class="ft-mgmt-donut-center"><strong>{{ $statusTotal }}</strong><span>tasks</span></div></div>
                <div class="ft-mgmt-legend">
                    @forelse($statusRows as $row)
                        <a href="{{ route('my-work', ['source' => $taskStatusTab, 'status' => $row['label']]) }}" wire:navigate title="View My Tasks filtered by {{ $row['label'] }}"><span class="dot" style="background:{{ $row['color'] }}"></span>{{ $row['label'] }}</a><b>{{ $row['count'] }}</b>
                    @empty
                        <span class="ft-mgmt-sub">No active task statuses.</span><b>0</b>
                    @endforelse
                </div>
            </div>
        </article>
        </div>
</section>
