@props([
    'label', 'property', 'type', 'value' => '', 'context' => '', 'placeholder' => 'All',
    'initialOptions' => collect(), 'selectedLabel' => null, 'params' => [], 'disabled' => false,
    'clearable' => true, 'action' => null, 'menuWidth' => 300, 'fixedMenu' => false,
])
<x-ui.search-select
    {{ $attributes }}
    :label="$label" :property="$property" :type="$type" :value="$value" :context="$context"
    :placeholder="$placeholder" :initial-options="$initialOptions" :selected-label="$selectedLabel"
    :params="$params" :disabled="$disabled" :clearable="$clearable" :action="$action"
    :menu-width="$menuWidth" :fixed-menu="$fixedMenu"
/>
