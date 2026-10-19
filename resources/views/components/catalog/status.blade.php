@props(['active' => true])
<span @class(['ft-product-status-pill', 'is-inactive' => !$active])>
    {{ $active ? 'Active' : 'Inactive' }}
</span>
