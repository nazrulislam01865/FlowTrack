@props(['status' => 'active'])
@php($active = strtolower((string) $status) === 'active')
<span {{ $attributes->class(['ft-supplier-list-status', 'is-active' => $active, 'is-inactive' => !$active]) }}>
    {{ $active ? 'Active' : 'Inactive' }}
</span>
