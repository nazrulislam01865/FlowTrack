@props([
    'model',
    'options' => [],
    'disabled' => false,
    'placeholder' => 'Select roles',
])
@php
    $componentId = 'ft-multi-role-'.md5($model.'-'.json_encode($options));
    $normalizedOptions = collect($options)->map(fn ($role) => [
        'id' => (string) data_get($role, 'id'),
        'name' => (string) data_get($role, 'name'),
    ])->values()->all();
@endphp
<div
    class="ft-multi-role-select"
    x-data="{
        open: false,
        search: '',
        selected: $wire.entangle('{{ $model }}').live,
        options: @js($normalizedOptions),
        nameFor(id) { return this.options.find(role => String(role.id) === String(id))?.name || String(id); },
        visible(role) { return !this.search || role.name.toLowerCase().includes(this.search.toLowerCase()); }
    }"
    x-on:keydown.escape.window="open = false"
>
    <button id="{{ $componentId }}" type="button" class="ft-multi-role-trigger" x-on:click="open = !open" @disabled($disabled)>
        <span class="ft-multi-role-chips" x-show="selected && selected.length">
            <template x-for="roleId in selected" :key="String(roleId)">
                <span class="ft-multi-role-chip" x-text="nameFor(roleId)"></span>
            </template>
        </span>
        <span class="ft-multi-role-placeholder" x-show="!selected || !selected.length">{{ $placeholder }}</span>
        <span class="ft-multi-role-caret" aria-hidden="true">⌄</span>
    </button>

    <div class="ft-multi-role-menu" x-show="open" x-cloak x-on:click.outside="open = false">
        <div class="ft-multi-role-search-wrap">
            <input type="search" x-model="search" placeholder="Search roles..." aria-label="Search roles">
        </div>
        <div class="ft-multi-role-options">
            <template x-for="role in options" :key="role.id">
                <label class="ft-multi-role-option" x-show="visible(role)">
                    <input type="checkbox" :value="role.id" x-model="selected" @disabled($disabled)>
                    <span x-text="role.name"></span>
                </label>
            </template>
            <div class="ft-multi-role-empty" x-show="options.filter(role => visible(role)).length === 0">No roles found.</div>
        </div>
    </div>
</div>
