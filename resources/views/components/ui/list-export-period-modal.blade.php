@props([
    'action',
    'filters' => [],
    'buttonClass' => '',
    'entityLabel' => 'records',
])

@php
    $exportModalId = 'ft-export-period-'.substr(md5((string) $action.'|'.(string) $entityLabel), 0, 10);
    $currentMonth = app(\App\Services\WorkspaceSettingsService::class)->localNow()->format('Y-m');
    $safeFilters = collect($filters)
        ->except(['date_from', 'date_to', 'export_period', 'export_month'])
        ->filter(static fn ($value) => $value !== null && $value !== '')
        ->all();
@endphp
<div
    class="ft-export-period"
    x-data="{ open: false, period: 'this_month', selectedMonth: @js($currentMonth) }"
    x-on:keydown.escape.window="open = false"
>
    <button
        type="button"
        class="{{ $buttonClass }} ft-export-period-trigger"
        x-on:click="open = true"
        aria-haspopup="dialog"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="{{ $exportModalId }}"
        title="Choose a report period and export {{ $entityLabel }}"
    >⇩ Export</button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        class="ft-export-period-layer"
        x-on:click.self="open = false"
    >
        <section
            id="{{ $exportModalId }}"
            class="ft-export-period-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $exportModalId }}-title"
            x-on:click.stop
        >
            <div class="ft-export-period-head">
                <div>
                    <h2 id="{{ $exportModalId }}-title">Export {{ ucfirst($entityLabel) }}</h2>
                    <p>Choose the created-date period for the report. Other active list filters and your access scope will still apply.</p>
                </div>
                <button type="button" class="ft-export-period-close" x-on:click="open = false" aria-label="Close export options">×</button>
            </div>

            <form class="ft-export-period-body" method="GET" action="{{ $action }}">
                @foreach($safeFilters as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach

                <span class="ft-export-period-label">Report period</span>
                <div class="ft-export-period-options">
                    @foreach([
                        'today' => ['Today', 'Created today'],
                        'last_7_days' => ['Last 7 days', 'Today + previous 6 days'],
                        'last_30_days' => ['Last 30 days', 'Today + previous 29 days'],
                        'this_month' => ['This month', 'Current calendar month'],
                        'selected_month' => ['Selected month', 'Choose any calendar month'],
                        'all_time' => ['All time', 'No created-date restriction'],
                    ] as $value => [$label, $help])
                        <label class="ft-export-period-option" :class="period === '{{ $value }}' ? 'is-active' : ''">
                            <input type="radio" name="export_period" value="{{ $value }}" x-model="period">
                            <span class="ft-export-period-copy">
                                <strong>{{ $label }}</strong>
                                <small>{{ $help }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="ft-export-period-month" x-cloak x-show="period === 'selected_month'">
                    <label for="{{ $exportModalId }}-month">Select month</label>
                    <div
                        class="ft-export-period-month-control"
                        role="button"
                        tabindex="0"
                        aria-label="Open month picker"
                        x-on:click="const picker = $refs.monthPicker; if (!picker) return; picker.focus({ preventScroll: true }); if (typeof picker.showPicker === 'function') { try { picker.showPicker(); } catch (e) {} }"
                        x-on:keydown.enter.prevent="const picker = $refs.monthPicker; if (!picker) return; picker.focus({ preventScroll: true }); if (typeof picker.showPicker === 'function') { try { picker.showPicker(); } catch (e) {} }"
                        x-on:keydown.space.prevent="const picker = $refs.monthPicker; if (!picker) return; picker.focus({ preventScroll: true }); if (typeof picker.showPicker === 'function') { try { picker.showPicker(); } catch (e) {} }"
                    >
                        <input
                            id="{{ $exportModalId }}-month"
                            x-ref="monthPicker"
                            type="month"
                            name="export_month"
                            x-model="selectedMonth"
                            :required="period === 'selected_month'"
                        >
                    </div>
                </div>

                <p class="ft-export-period-note">The export includes all matching records in the chosen period, not only the rows currently visible on the page.</p>

                <div class="ft-export-period-actions">
                    <button type="button" class="ft-export-period-cancel" x-on:click="open = false">Cancel</button>
                    <button type="submit" class="ft-export-period-submit">Generate &amp; Export</button>
                </div>
            </form>
        </section>
    </div>
</div>
