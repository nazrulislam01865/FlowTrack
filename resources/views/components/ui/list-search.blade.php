@props(['property' => 'search', 'value' => '', 'placeholder' => 'Search…', 'label' => 'Search'])
<x-ui.search-input {{ $attributes }} :property="$property" :value="$value" :placeholder="$placeholder" :label="$label" />
