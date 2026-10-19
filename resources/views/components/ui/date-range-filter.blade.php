@props([
    'fromProperty' => 'dateFrom', 'toProperty' => 'dateTo', 'fromValue' => '', 'toValue' => '',
    'label' => 'Created date', 'fromLabel' => 'Date from', 'toLabel' => 'Date to',
])
<x-ui.date-range {{ $attributes }} :from-property="$fromProperty" :to-property="$toProperty" :from-value="$fromValue" :to-value="$toValue" :label="$label" :from-label="$fromLabel" :to-label="$toLabel" />
