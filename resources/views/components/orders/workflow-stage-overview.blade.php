@props([
    'stages' => collect(),
    'selectedStageId' => null,
    'selectedStageValue' => null,
    'mode' => 'navigate',
    'title' => 'Orders by workflow stage',
    'description' => 'Click a stage to view the matching orders.',
    'showAllLabel' => 'Show all',
    'showHeader' => true,
    'filterMethod' => 'selectStage',
    'countLabel' => 'Current orders',
    'navigationQuery' => [],
])

@php
    $filterMode = $mode === 'filter';
    $wireFilterMode = $mode === 'wire-filter';
    $interactiveFilterMode = $filterMode || $wireFilterMode;
    $selectedKey = filled($selectedStageValue)
        ? (string) $selectedStageValue
        : (filled($selectedStageId) ? (string) $selectedStageId : '');

    $stageTextColor = static function (?string $color): string {
        $hex = trim((string) $color);

        if (! preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $matches)) {
            return '#ffffff';
        }

        $rgb = $matches[1];
        $red = hexdec(substr($rgb, 0, 2));
        $green = hexdec(substr($rgb, 2, 2));
        $blue = hexdec(substr($rgb, 4, 2));
        $luminance = (0.299 * $red) + (0.587 * $green) + (0.114 * $blue);

        return $luminance >= 170 ? '#16324f' : '#ffffff';
    };
@endphp

<section class="ft-order-workflow-stage-overview" aria-label="{{ $title }}">
    @if($showHeader)
        <div class="ft-order-workflow-stage-head">
            <div>
                <h2>{{ $title }}</h2>
                <p>{{ $description }}</p>
            </div>

            @if($filterMode)
                <button
                    class="ft-order-workflow-stage-show-all"
                    type="button"
                    wire:click="selectStage(null)"
                >
                    {{ $showAllLabel }}
                </button>
            @elseif($wireFilterMode)
                <button
                    class="ft-order-workflow-stage-show-all"
                    type="button"
                    wire:click="{{ $filterMethod }}('')"
                >
                    {{ $showAllLabel }}
                </button>
            @else
                <a
                    class="ft-order-workflow-stage-show-all"
                    href="{{ route('jobs.index') }}"
                    wire:navigate.hover
                >
                    {{ $showAllLabel }}
                </a>
            @endif
        </div>
    @endif

    <div class="ft-order-workflow-stage-strip">
        @foreach($stages as $stage)
            @php
                $stageId = (int) data_get($stage, 'id');
                $stageValue = data_get($stage, 'filter_value', $stageId);
                $stageColor = (string) data_get($stage, 'color', '#2d72d9');
                $isSelectedStage = $interactiveFilterMode
                    && $selectedKey !== ''
                    && $selectedKey === (string) $stageValue;
                $stageName = (string) (data_get($stage, 'short_name') ?: data_get($stage, 'name'));
                $stageCountLabel = (string) data_get($stage, 'count_label', $countLabel);
                $style = '--stage:'.$stageColor.';--stage-text:'.$stageTextColor($stageColor);
            @endphp

            @if($filterMode)
                <button
                    type="button"
                    class="ft-order-workflow-stage-card {{ $isSelectedStage ? 'active' : '' }}"
                    style="{{ $style }}"
                    wire:click="selectStage({{ $stageId }})"
                    aria-pressed="{{ $isSelectedStage ? 'true' : 'false' }}"
                >
                    @if($isSelectedStage)
                        <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
                    @endif

                    <span class="ft-order-workflow-stage-kicker">Stage {{ (int) data_get($stage, 'sequence') }}</span>
                    <b title="{{ data_get($stage, 'name') }}">{{ $stageName }}</b>
                    <span class="ft-order-workflow-stage-count">
                        <em>{{ $stageCountLabel }}</em>
                        <strong>{{ number_format((int) data_get($stage, 'count', 0)) }}</strong>
                    </span>
                </button>
            @elseif($wireFilterMode)
                <button
                    type="button"
                    class="ft-order-workflow-stage-card {{ $isSelectedStage ? 'active' : '' }}"
                    style="{{ $style }}"
                    wire:click="{{ $filterMethod }}('{{ str_replace("'", "\\'", (string) $stageValue) }}')"
                    aria-pressed="{{ $isSelectedStage ? 'true' : 'false' }}"
                >
                    @if($isSelectedStage)
                        <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
                    @endif

                    <span class="ft-order-workflow-stage-kicker">Stage {{ (int) data_get($stage, 'sequence') }}</span>
                    <b title="{{ data_get($stage, 'name') }}">{{ $stageName }}</b>
                    <span class="ft-order-workflow-stage-count">
                        <em>{{ $stageCountLabel }}</em>
                        <strong>{{ number_format((int) data_get($stage, 'count', 0)) }}</strong>
                    </span>
                </button>
            @else
                <a
                    class="ft-order-workflow-stage-card"
                    style="{{ $style }}"
                    href="{{ route('jobs.index', array_merge($navigationQuery, ['phase' => $stageId])) }}"
                    wire:navigate.hover
                >
                    <span class="ft-order-workflow-stage-kicker">Stage {{ (int) data_get($stage, 'sequence') }}</span>
                    <b title="{{ data_get($stage, 'name') }}">{{ $stageName }}</b>
                    <span class="ft-order-workflow-stage-count">
                        <em>{{ $stageCountLabel }}</em>
                        <strong>{{ number_format((int) data_get($stage, 'count', 0)) }}</strong>
                    </span>
                </a>
            @endif
        @endforeach
    </div>

    @isset($summaryCards)
        <div class="ft-order-workflow-metric-strip" aria-label="Order summary filters">
            {{ $summaryCards }}
        </div>
    @endisset
</section>
