<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
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
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
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
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
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
?>
<div
    <?php echo e($attributes->class(['ft-jl-control', 'ft-remote-filter', 'ft-search-select'])); ?>

    data-ft-ui-component="search-select"
    x-bind:class="{ 'is-disabled': disabled }"
    <?php if($remote): ?>
        x-data="window.FlowTrack.ui.searchSelect({
            property: <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>,
            type: <?php echo \Illuminate\Support\Js::from($type)->toHtml() ?>,
            context: <?php echo \Illuminate\Support\Js::from($context)->toHtml() ?>,
            value: <?php echo \Illuminate\Support\Js::from((string) $value)->toHtml() ?>,
            placeholder: <?php echo \Illuminate\Support\Js::from($placeholder)->toHtml() ?>,
            selectedLabel: <?php echo \Illuminate\Support\Js::from($resolvedLabel)->toHtml() ?>,
            endpoint: <?php echo \Illuminate\Support\Js::from(route('filter-options.index', ['type' => $type]))->toHtml() ?>,
            initialItems: <?php echo \Illuminate\Support\Js::from($items->all())->toHtml() ?>,
            params: <?php echo \Illuminate\Support\Js::from($params)->toHtml() ?>,
            disabled: <?php echo \Illuminate\Support\Js::from((bool) $disabled)->toHtml() ?>,
            menuWidth: <?php echo \Illuminate\Support\Js::from((int) $menuWidth)->toHtml() ?>,
            fixedMenu: <?php echo \Illuminate\Support\Js::from((bool) $fixedMenu)->toHtml() ?>,
        })"
        x-effect="syncSelection(<?php echo \Illuminate\Support\Js::from(['value' => (string) $value, 'label' => $resolvedLabel])->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($params)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($items->all())->toHtml() ?>); syncDisabled(<?php echo \Illuminate\Support\Js::from((bool) $disabled)->toHtml() ?>)"
        x-on:flowtrack-search-select-sync.window="if ($event.detail?.property === <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>) syncSelection({ value: String($event.detail?.value || ''), label: String($event.detail?.label || $event.detail?.value || <?php echo \Illuminate\Support\Js::from($placeholder)->toHtml() ?>) }, <?php echo \Illuminate\Support\Js::from($params)->toHtml() ?>, Array.isArray($event.detail?.items) ? $event.detail.items : items)"
    <?php else: ?>
        x-data="window.FlowTrack.ui.localFilter({
            property: <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>,
            value: <?php echo \Illuminate\Support\Js::from((string) $value)->toHtml() ?>,
            placeholder: <?php echo \Illuminate\Support\Js::from($placeholder)->toHtml() ?>,
            selectedLabel: <?php echo \Illuminate\Support\Js::from($resolvedLabel)->toHtml() ?>,
            items: <?php echo \Illuminate\Support\Js::from($items->all())->toHtml() ?>,
            disabled: <?php echo \Illuminate\Support\Js::from((bool) $disabled)->toHtml() ?>,
            menuWidth: <?php echo \Illuminate\Support\Js::from((int) $menuWidth)->toHtml() ?>,
            fixedMenu: <?php echo \Illuminate\Support\Js::from((bool) $fixedMenu)->toHtml() ?>,
        })"
        x-effect="syncOptions(<?php echo \Illuminate\Support\Js::from((string) $value)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($resolvedLabel)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($items->all())->toHtml() ?>, <?php echo \Illuminate\Support\Js::from((bool) $disabled)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($placeholder)->toHtml() ?>)"
    <?php endif; ?>
    x-on:keydown.escape.window="close()"
    x-on:resize.window="open && reposition()"
    x-on:scroll.window="open && reposition()"
>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($hideLabel)): ?>
        <label id="<?php echo e($componentId); ?>-label"><?php echo e($label); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($required): ?><i class="ft-filter-required" aria-hidden="true">*</i><?php elseif($optional): ?><em class="ft-filter-optional">Optional</em><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></label>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <button
        x-ref="trigger"
        type="button"
        class="ft-remote-filter-button ft-search-select__trigger"
        aria-haspopup="listbox"
        aria-controls="<?php echo e($componentId); ?>-listbox"
        <?php if (! ($hideLabel)): ?> aria-labelledby="<?php echo e($componentId); ?>-label" <?php else: ?> aria-label="<?php echo e($label); ?>" <?php endif; ?>
        x-on:click.stop="toggle()"
        :aria-expanded="open.toString()"
        x-bind:disabled="disabled"
    >
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showAvatar): ?>
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
        <?php else: ?>
            <span class="ft-search-select__value" x-text="selectedLabel"></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <span class="ft-filter-chevron" aria-hidden="true">⌄</span>
    </button>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($fixedMenu): ?>
        <template x-teleport="body">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div
        x-ref="menu"
        class="ft-remote-filter-menu ft-search-select__menu<?php echo e($showAvatar ? ' ft-search-select__menu--people' : ''); ?>"
        data-ft-search-select-context="<?php echo e($context); ?>"
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
            <?php if($remote): ?> x-on:input.debounce.300ms="searchOptions()" <?php endif; ?>
            x-on:keydown.arrow-down.prevent="focusFirst()"
            placeholder="<?php echo e($resolvedSearchPlaceholder); ?>"
            autocomplete="off"
        >

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($clearable): ?>
            <button
                type="button"
                class="ft-remote-filter-option ft-remote-filter-clear"
                :aria-selected="selectedValue === ''"
                x-on:click.stop="<?php if($remote): ?> clearSelection() <?php else: ?> choose('', <?php echo \Illuminate\Support\Js::from($placeholder)->toHtml() ?>) <?php endif; ?>; $dispatch('flowtrack-selection-changed', {property: <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, value: '', label: <?php echo \Illuminate\Support\Js::from($placeholder)->toHtml() ?>}); <?php if($action): ?> Promise.resolve($wire.call(<?php echo \Illuminate\Support\Js::from($action)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, '')).catch(() => <?php if($remote): ?> selectionFailed() <?php else: ?> null <?php endif; ?>) <?php else: ?> $nextTick(() => Promise.resolve($wire.set(<?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, '')).catch(() => <?php if($remote): ?> selectionFailed() <?php else: ?> null <?php endif; ?>)) <?php endif; ?>"
            >
                <span><?php echo e($placeholder); ?></span><small x-show="selectedValue === ''">Clear</small>
            </button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <div
            id="<?php echo e($componentId); ?>-listbox"
            class="ft-remote-filter-list ft-search-select__list<?php echo e($infiniteScroll ? ' ft-search-select__list--infinite' : ''); ?>"
            role="listbox"
            <?php if($remote && $infiniteScroll): ?>
                x-on:scroll.passive="if (hasMore && !loading && ($el.scrollHeight - $el.scrollTop - $el.clientHeight <= 56)) loadMore()"
            <?php endif; ?>
        >
            <template x-if="loading && visibleItems.length === 0"><div><div class="ft-filter-skeleton"></div><div class="ft-filter-skeleton"></div></div></template>
            <template x-if="!loading && visibleItems.length === 0"><div class="ft-remote-filter-message">No matching options</div></template>
            <template x-for="item in visibleItems" :key="item.id">
                <button
                    type="button"
                    role="option"
                    class="ft-remote-filter-option ft-search-select__option"
                    :aria-selected="String(item.id) === String(selectedValue)"
                    x-on:click.stop="<?php if($remote): ?> select(item) <?php else: ?> choose(String(item.id), item.label) <?php endif; ?>; $dispatch('flowtrack-selection-changed', {property: <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, value: String(item.id), label: item.label}); <?php if($action): ?> Promise.resolve($wire.call(<?php echo \Illuminate\Support\Js::from($action)->toHtml() ?>, <?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, String(item.id))).catch(() => <?php if($remote): ?> selectionFailed() <?php else: ?> null <?php endif; ?>) <?php else: ?> $nextTick(() => Promise.resolve($wire.set(<?php echo \Illuminate\Support\Js::from($property)->toHtml() ?>, String(item.id))).catch(() => <?php if($remote): ?> selectionFailed() <?php else: ?> null <?php endif; ?>)) <?php endif; ?>"
                >
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showAvatar): ?>
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
                    <?php else: ?>
                        <span x-text="item.label"></span>
                        <small x-text="item.meta || (String(item.id) === String(selectedValue) ? 'Selected' : '')"></small>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </button>
            </template>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($remote && $infiniteScroll): ?>
                
                <div class="ft-remote-filter-message ft-search-select__scroll-status" x-show="loading && visibleItems.length > 0">Loading more…</div>
                <div class="ft-remote-filter-message ft-search-select__scroll-status" x-show="!loading && hasMore && message !== 'Could not load options. Try again.'">Scroll to load more</div>
                <div class="ft-remote-filter-message ft-search-select__scroll-status" x-show="!loading && message === 'Could not load options. Try again.'" x-text="message"></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($remote && !$infiniteScroll): ?>
            <button type="button" class="ft-search-select__load-more" x-show="hasMore && !loading" x-on:click="loadMore()">Load more</button>
            <div class="ft-remote-filter-message" x-text="message"></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($remote)): ?>
            <div class="ft-remote-filter-message" x-text="message"></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($footerMessage): ?><div class="ft-remote-filter-message"><?php echo e($footerMessage); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($fixedMenu): ?>
        </template>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/search-select.blade.php ENDPATH**/ ?>