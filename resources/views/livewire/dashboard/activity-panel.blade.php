@php
    $orderTerminology = static function (?string $value): string {
        return preg_replace_callback('/\bjobs?\b/i', static function (array $match): string {
            return match ($match[0]) {
                'Jobs' => 'Orders', 'jobs' => 'orders', 'JOB' => 'ORDER', 'JOBS' => 'ORDERS',
                default => ctype_upper($match[0][0] ?? '') ? 'Order' : 'order',
            };
        }, (string) $value) ?: (string) $value;
    };

@endphp
    <article class="ft-mgmt-panel ft-mgmt-recent-prototype" data-dashboard-panel="activity">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-activity-content">
            <div class="ft-mgmt-panel-head">
                <div><h2>Recent activity</h2><p>Latest changes from Orders, Inquiries and Tasks</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setActivityTab('all')" class="ft-mgmt-tab {{ $activityTab === 'all' ? 'active' : '' }}">All</button>
                    <button type="button" wire:click="setActivityTab('orders')" class="ft-mgmt-tab {{ $activityTab === 'orders' ? 'active' : '' }}">Orders</button>
                    <button type="button" wire:click="setActivityTab('inquiries')" class="ft-mgmt-tab {{ $activityTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                    <button type="button" wire:click="setActivityTab('tasks')" class="ft-mgmt-tab {{ $activityTab === 'tasks' ? 'active' : '' }}">Tasks</button>
                </div>
            </div>
            <div class="ft-mgmt-activity-list">
                @forelse($recentActivity as $activity)
                    @php
                        $activityDetail = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $orderTerminology($activity->dashboard_detail), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                        $activityKind = (string) ($activity->dashboard_kind ?? 'orders');
                        $activityIsInquiry = $activity->subject_type === \App\Models\Inquiry::class;
                        $activityRoute = ($activityKind === 'inquiries' || $activityIsInquiry)
                            ? route('inquiries.index', ['open' => (int) ($activity->dashboard_parent_id ?? 0)])
                            : route('jobs.index', array_filter([
                                'open' => (int) ($activity->dashboard_parent_id ?? 0),
                                'task' => $activityKind === 'tasks' && (int) ($activity->dashboard_task_id ?? 0) > 0 ? (int) $activity->dashboard_task_id : null,
                            ]));
                    @endphp
                    <a class="ft-mgmt-activity" href="{{ $activityRoute }}" wire:navigate wire:key="mgmt-activity-{{ $activity->id }}">
                        <x-ui.avatar :user="$activity->user" :name="$activity->user?->name ?? 'FlowTrack'" :size="38" />
                        <span class="ft-mgmt-activity-copy">
                            <strong>{{ $orderTerminology($activity->dashboard_title) }}</strong>
                            <p>{{ $activityDetail !== '' ? $activityDetail : 'Record updated' }}</p>
                        </span>
                        <time>{{ $activity->created_at?->diffForHumans(short: true) }}</time>
                    </a>
                @empty<div class="ft-mgmt-empty">No Order, Inquiry or Task changes match the selected period or filters.</div>@endforelse
            </div>
            </div>
</article>
