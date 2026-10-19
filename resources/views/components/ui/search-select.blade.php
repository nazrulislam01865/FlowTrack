@props([
    'label',
    'property',
    'type' => null,
    'value' => '',
    'context' => '',
    'placeholder' => 'Select',
    'initialOptions' => [],
    'options' => [],
    'selectedLabel' => null,
    'params' => [],
    'disabled' => false,
    'clearable' => true,
    'action' => null,
    'menuWidth' => 300,
    'fixedMenu' => false,
    'required' => false,
    'optional' => false,
    'hideLabel' => false,
    'searchPlaceholder' => null,
    'footerMessage' => null,
    'showAvatar' => false,
    // Optional progressive paging for selectors that are expected to browse
    // many records. The shared remote selector still fetches nothing until the
    // trigger is opened; when enabled, reaching the bottom of the option list
    // automatically requests the next bounded page instead of showing a
    // separate "Load more" button.
    'infiniteScroll' => false,
])
@php
    $remote = filled($type);
    $sourceOptions = collect($remote ? $initialOptions : ($options ?: $initialOptions));
    $items = $sourceOptions->map(function ($item) {
        if (is_array($item)) {
            return [
                'id' => (string) ($item['id'] ?? $item['value'] ?? $item['name'] ?? ''),
                'label' => (string) ($item['label'] ?? $item['name'] ?? $item['value'] ?? $item['id'] ?? ''),
                'meta' => (string) ($item['meta'] ?? ''),
                'avatarUrl' => (string) ($item['avatarUrl'] ?? ''),
            ];
        }

        if (is_object($item)) {
            return [
                'id' => (string) ($item->id ?? $item->value ?? $item->name ?? ''),
                'label' => (string) ($item->label ?? $item->name ?? $item->value ?? $item->id ?? ''),
                'meta' => (string) ($item->meta ?? ''),
                'avatarUrl' => (string) ($item->avatarUrl ?? ''),
            ];
        }

        return ['id' => (string) $item, 'label' => (string) $item, 'meta' => '', 'avatarUrl' => ''];
    })->filter(fn ($item) => $item['id'] !== '')->values();

    $normalise = static function ($candidate): string {
        $candidate = strtolower(trim((string) $candidate));
        $candidate = preg_replace('/[_-]+/', ' ', $candidate) ?? $candidate;
        return trim(preg_replace('/\s+/', ' ', $candidate) ?? $candidate);
    };
    $selected = $items->first(fn ($item) => (string) $item['id'] === (string) $value
        || ((string) $value !== '' && $normalise($item['id']) === $normalise($value)));
    $resolvedLabel = $selectedLabel ?: ($selected['label'] ?? ((string) $value !== '' ? (string) $value : $placeholder));
    $resolvedSearchPlaceholder = $searchPlaceholder ?: 'Search '.strtolower((string) $label).'…';
    $componentId = 'ft-search-select-'.substr(md5($property.'|'.$type.'|'.$context.'|'.$label), 0, 12);
@endphp
<div
    {{ $attributes->class(['ft-jl-control', 'ft-remote-filter', 'ft-search-select']) }}
    data-ft-ui-component="search-select"
    x-bind:class="{ 'is-disabled': disabled }"
    @if($remote)
        x-data="window.FlowTrack.ui.searchSelect({
            property: @js($property),
            type: @js($type),
            context: @js($context),
            value: @js((string) $value),
            placeholder: @js($placeholder),
            selectedLabel: @js($resolvedLabel),
            endpoint: @js(route('filter-options.index', ['type' => $type])),
            initialItems: @js($items->all()),
            params: @js($params),
            disabled: @js((bool) $disabled),
            menuWidth: @js((int) $menuWidth),
            fixedMenu: @js((bool) $fixedMenu),
        })"
        x-effect="syncSelection(@js(['value' => (string) $value, 'label' => $resolvedLabel]), @js($params), @js($items->all())); syncDisabled(@js((bool) $disabled))"
        x-on:flowtrack-search-select-sync.window="if ($event.detail?.property === @js($property)) syncSelection({ value: String($event.detail?.value || ''), label: String($event.detail?.label || $event.detail?.value || @js($placeholder)) }, @js($params), Array.isArray($event.detail?.items) ? $event.detail.items : items)"
    @else
        x-data="window.FlowTrack.ui.localFilter({
            property: @js($property),
            value: @js((string) $value),
            placeholder: @js($placeholder),
            selectedLabel: @js($resolvedLabel),
            items: @js($items->all()),
            disabled: @js((bool) $disabled),
            menuWidth: @js((int) $menuWidth),
            fixedMenu: @js((bool) $fixedMenu),
        })"
        x-effect="syncOptions(@js((string) $value), @js($resolvedLabel), @js($items->all()), @js((bool) $disabled), @js($placeholder))"
    @endif
    x-on:keydown.escape.window="close()"
    x-on:resize.window="open && reposition()"
    x-on:scroll.window="open && reposition()"
>
    @unless($hideLabel)
        <label id="{{ $componentId }}-label">{{ $label }}@if($required)<i class="ft-filter-required" aria-hidden="true">*</i>@elseif($optional)<em class="ft-filter-optional">Optional</em>@endif</label>
    @endunless

    <button
        x-ref="trigger"
        type="button"
        class="ft-remote-filter-button ft-search-select__trigger"
        aria-haspopup="listbox"
        aria-controls="{{ $componentId }}-listbox"
        @unless($hideLabel) aria-labelledby="{{ $componentId }}-label" @else aria-label="{{ $label }}" @endunless
        x-on:click.stop="toggle()"
        :aria-expanded="open.toString()"
        x-bind:disabled="disabled"
    >
        @if($showAvatar)
            <span class="ft-search-select__selected-user">
                <span class="ft-search-select__avatar ft-search-select__avatar--trigger" x-show="selectedValue">
                    <template x-if="items.find((candidate) => String(candidate?.id) === String(selectedValue))?.avatarUrl">
                        <img
                            :src="items.find((candidate) => String(candidate?.id) === String(selectedValue))?.avatarUrl"
                            :alt="selectedLabel"
                            loading="lazy"
                        >
                    </template>
                    <span
                        x-show="!items.find((candidate) => String(candidate?.id) === String(selectedValue))?.avatarUrl"
                        x-text="selectedLabel ? selectedLabel.trim().charAt(0).toUpperCase() : '?'"
                    ></span>
                </span>
                <span class="ft-search-select__value" x-text="selectedLabel"></span>
            </span>
        @else
            <span class="ft-search-select__value" x-text="selectedLabel"></span>
        @endif
        <span class="ft-filter-chevron" aria-hidden="true">⌄</span>
    </button>

    @if($fixedMenu)
        <template x-teleport="body">
    @endif
    <div
        x-ref="menu"
        class="ft-remote-filter-menu ft-search-select__menu{{ $showAvatar ? ' ft-search-select__menu--people' : '' }}"
        data-ft-search-select-context="{{ $context }}"
        x-cloak
        x-bind:style="open ? menuStyle + ';display:flex!important;' : 'display:none!important;'"
        x-on:click.outside="close()"
        x-on:keydown.arrow-down.prevent="moveOption(1)"
        x-on:keydown.arrow-up.prevent="moveOption(-1)"
        x-on:keydown.home.prevent="focusBoundary('first')"
        x-on:keydown.end.prevent="focusBoundary('last')"
    >
        <input
            x-ref="search"
            class="ft-remote-filter-search ft-search-select__search"
            type="text"
            role="searchbox"
            inputmode="search"
            x-model="query"
            @if($remote) x-on:input.debounce.300ms="searchOptions()" @endif
            x-on:keydown.arrow-down.prevent="focusFirst()"
            placeholder="{{ $resolvedSearchPlaceholder }}"
            autocomplete="off"
        >

        @if($clearable)
            <button
                type="button"
                class="ft-remote-filter-option ft-remote-filter-clear"
                :aria-selected="selectedValue === ''"
                x-on:click.stop="@if($remote) clearSelection() @else choose('', @js($placeholder)) @endif; $dispatch('flowtrack-selection-changed', {property: @js($property), value: '', label: @js($placeholder)}); @if($action) Promise.resolve($wire.call(@js($action), @js($property), '')).catch(() => @if($remote) selectionFailed() @else null @endif) @else $nextTick(() => Promise.resolve($wire.set(@js($property), '')).catch(() => @if($remote) selectionFailed() @else null @endif)) @endif"
            >
                <span>{{ $placeholder }}</span><small x-show="selectedValue === ''">Clear</small>
            </button>
        @endif

        <div
            id="{{ $componentId }}-listbox"
            class="ft-remote-filter-list ft-search-select__list{{ $infiniteScroll ? ' ft-search-select__list--infinite' : '' }}"
            role="listbox"
            @if($remote && $infiniteScroll)
                x-on:scroll.passive="if (hasMore && !loading && ($el.scrollHeight - $el.scrollTop - $el.clientHeight <= 56)) loadMore()"
            @endif
        >
            <template x-if="loading && visibleItems.length === 0"><div><div class="ft-filter-skeleton"></div><div class="ft-filter-skeleton"></div></div></template>
            <template x-if="!loading && visibleItems.length === 0"><div class="ft-remote-filter-message">No matching options</div></template>
            <template x-for="item in visibleItems" :key="item.id">
                <button
                    type="button"
                    role="option"
                    class="ft-remote-filter-option ft-search-select__option"
                    :aria-selected="String(item.id) === String(selectedValue)"
                    x-on:click.stop="@if($remote) select(item) @else choose(String(item.id), item.label) @endif; $dispatch('flowtrack-selection-changed', {property: @js($property), value: String(item.id), label: item.label}); @if($action) Promise.resolve($wire.call(@js($action), @js($property), String(item.id))).catch(() => @if($remote) selectionFailed() @else null @endif) @else $nextTick(() => Promise.resolve($wire.set(@js($property), String(item.id))).catch(() => @if($remote) selectionFailed() @else null @endif)) @endif"
                >
                    @if($showAvatar)
                        <span class="ft-search-select__user-option">
                            <span class="ft-search-select__avatar" aria-hidden="true">
                                <template x-if="item.avatarUrl">
                                    <img :src="item.avatarUrl" :alt="item.label" loading="lazy">
                                </template>
                                <span
                                    x-show="!item.avatarUrl"
                                    x-text="item.label ? item.label.trim().charAt(0).toUpperCase() : '?'"
                                ></span>
                            </span>
                            <span class="ft-search-select__user-copy">
                                <strong x-text="item.label"></strong>
                                <small x-show="item.meta" x-text="item.meta"></small>
                            </span>
                        </span>
                        <small class="ft-search-select__selected-mark" x-show="String(item.id) === String(selectedValue)">Selected</small>
                    @else
                        <span x-text="item.label"></span>
                        <small x-text="item.meta || (String(item.id) === String(selectedValue) ? 'Selected' : '')"></small>
                    @endif
                </button>
            </template>

            @if($remote && $infiniteScroll)
                {{-- Keep infinite-scroll feedback inside the scroll container.
                     This makes wheel/touch scrolling work even when the pointer
                     is over the hint itself and keeps the load trigger attached
                     to the element that actually owns scrollTop. --}}
                <div class="ft-remote-filter-message ft-search-select__scroll-status" x-show="loading && visibleItems.length > 0">Loading more…</div>
                <div class="ft-remote-filter-message ft-search-select__scroll-status" x-show="!loading && hasMore && message !== 'Could not load options. Try again.'">Scroll to load more</div>
                <div class="ft-remote-filter-message ft-search-select__scroll-status" x-show="!loading && message === 'Could not load options. Try again.'" x-text="message"></div>
            @endif
        </div>

        @if($remote && !$infiniteScroll)
            <button type="button" class="ft-search-select__load-more" x-show="hasMore && !loading" x-on:click="loadMore()">Load more</button>
            <div class="ft-remote-filter-message" x-text="message"></div>
        @endif
        @unless($remote)
            <div class="ft-remote-filter-message" x-text="message"></div>
        @endunless
        @if($footerMessage)<div class="ft-remote-filter-message">{{ $footerMessage }}</div>@endif
    </div>
    @if($fixedMenu)
        </template>
    @endif
</div>
