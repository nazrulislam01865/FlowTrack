@props([
    'label', 'property', 'value' => '', 'placeholder' => 'All', 'options' => collect(),
    'selectedLabel' => null, 'disabled' => false, 'clearable' => true, 'action' => null,
    'menuWidth' => 300, 'searchPlaceholder' => null, 'required' => false, 'optional' => false,
    'fixedMenu' => false, 'footerMessage' => null, 'hideLabel' => false,
])
<x-ui.search-select
    {{ $attributes }}
    :label="$label" :property="$property" :value="$value" :placeholder="$placeholder"
    :options="$options" :selected-label="$selectedLabel" :disabled="$disabled" :clearable="$clearable"
    :action="$action" :menu-width="$menuWidth" :search-placeholder="$searchPlaceholder"
    :required="$required" :optional="$optional" :fixed-menu="$fixedMenu"
    :footer-message="$footerMessage" :hide-label="$hideLabel"
/>
