@props([
    'label', 'property', 'type' => null, 'context' => '', 'values' => [], 'options' => [],
    'initialOptions' => [], 'placeholder' => 'Select options', 'params' => [], 'disabled' => false,
    'menuWidth' => 320, 'fixedMenu' => false, 'maxSelected' => 100,
])
@php
    $remote = filled($type);
    $items = collect($remote ? $initialOptions : ($options ?: $initialOptions))->map(fn ($item) => [
        'id' => (string) data_get($item, 'id', data_get($item, 'value', data_get($item, 'name', ''))),
        'label' => (string) data_get($item, 'label', data_get($item, 'name', data_get($item, 'value', data_get($item, 'id', '')))),
        'meta' => (string) data_get($item, 'meta', ''),
    ])->filter(fn ($item) => $item['id'] !== '')->values();
    $values = collect($values)->map(fn ($value) => (string) $value)->filter()->unique()->values();
    $componentId = 'ft-multi-select-'.substr(md5($property.'|'.$type.'|'.$context.'|'.$label), 0, 12);
@endphp
<div
    {{ $attributes->class(['ft-multi-select', 'is-disabled' => $disabled]) }}
    data-ft-ui-component="multi-select"
    x-data="window.FlowTrack.ui.multiSelect({
        property: @js($property),
        endpoint: @js($remote ? route('filter-options.index', ['type' => $type]) : ''),
        context: @js($context),
        params: @js($params),
        remote: @js($remote),
        values: @js($values->all()),
        initialItems: @js($items->all()),
        placeholder: @js($placeholder),
        disabled: @js((bool) $disabled),
        menuWidth: @js((int) $menuWidth),
        fixedMenu: @js((bool) $fixedMenu),
        maxSelected: @js(max(1, min(100, (int) $maxSelected))),
    })"
    x-effect="syncValues(@js($values->all()), @js($items->all()), @js($params))"
    x-on:keydown.escape.window="close()"
    x-on:resize.window="open && reposition()"
    x-on:scroll.window="open && reposition()"
>
    <label id="{{ $componentId }}-label" class="ft-multi-select__label">{{ $label }}</label>
    <button type="button" class="ft-multi-select__trigger" x-ref="trigger" x-on:click="toggle()" :aria-expanded="open.toString()" aria-haspopup="listbox" aria-controls="{{ $componentId }}-listbox" aria-labelledby="{{ $componentId }}-label" @disabled($disabled)>
        <span class="ft-multi-select__chips" x-show="selected.length">
            <template x-for="item in selectedItems" :key="item.id"><span class="ft-multi-select__chip" x-text="item.label"></span></template>
        </span>
        <span class="ft-multi-select__placeholder" x-show="!selected.length">{{ $placeholder }}</span>
        <span aria-hidden="true">⌄</span>
    </button>
    <div class="ft-multi-select__menu" x-ref="menu" x-cloak x-show="open" x-bind:style="menuStyle" x-on:click.outside="close()">
        <input x-ref="search" class="ft-multi-select__search" type="text" role="searchbox" x-model="query" @if($remote) x-on:input.debounce.300ms="searchOptions()" @endif placeholder="Search {{ strtolower($label) }}…" autocomplete="off">
        <div id="{{ $componentId }}-listbox" class="ft-multi-select__options" role="listbox" aria-multiselectable="true">
            <template x-if="loading"><div class="ft-multi-select__message">Loading…</div></template>
            <template x-if="!loading && visibleItems.length === 0"><div class="ft-multi-select__message">No matching options</div></template>
            <template x-for="item in visibleItems" :key="item.id">
                <label class="ft-multi-select__option" :aria-selected="isSelected(item.id).toString()">
                    <input type="checkbox" :checked="isSelected(item.id)" x-on:change="toggleValue(item); $wire.set(@js($property), selected)" @disabled($disabled)>
                    <span><b x-text="item.label"></b><small x-text="item.meta"></small></span>
                </label>
            </template>
        </div>
        <button type="button" class="ft-multi-select__load-more" x-show="remote && hasMore && !loading" x-on:click="loadMore()">Load more</button>
        <div class="ft-multi-select__message" x-text="message"></div>
    </div>
</div>
