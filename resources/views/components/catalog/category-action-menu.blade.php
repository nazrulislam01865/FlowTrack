@props([
    'level',
    'recordId',
    'isActive' => true,
    'canEdit' => false,
    'canDelete' => false,
    'canCreate' => false,
])
@php
    $menuId = 'category-actions-'.$level.'-'.$recordId;
    $estimatedMenuHeight = 190;
@endphp
<div class="ft-category-action-menu" x-data="{ open:false, busy:false }">
    <button
        type="button"
        class="ft-category-action-trigger"
        aria-haspopup="menu"
        aria-controls="{{ $menuId }}"
        :aria-expanded="open ? 'true' : 'false'"
        x-on:click.stop="
            const menu=$refs.menu;
            if(menu.matches(':popover-open')){menu.hidePopover();return;}
            const rect=$el.getBoundingClientRect(), width=168, height={{ $estimatedMenuHeight }}, edge=10, gap=6;
            const above=(window.innerHeight-rect.bottom)<height+gap+edge && rect.top>(window.innerHeight-rect.bottom);
            menu.style.left=Math.min(window.innerWidth-width-edge,Math.max(edge,rect.right-width))+'px';
            menu.style.top=Math.min(window.innerHeight-height-edge,Math.max(edge,above?rect.top-height-gap:rect.bottom+gap))+'px';
            menu.showPopover();
        "
        aria-label="Category actions"
    ><span></span><span></span><span></span></button>

    <div id="{{ $menuId }}" class="ft-category-action-panel" x-ref="menu" popover="auto" role="menu" x-on:toggle="open=$event.newState==='open'">
        <button type="button" role="menuitem" x-on:click="$refs.menu.hidePopover(); $wire.viewCategory('{{ $level }}', {{ $recordId }})">View category</button>
        @if($canCreate && $level === 'product')
            <button type="button" role="menuitem" x-on:click="$refs.menu.hidePopover(); $wire.openCategoryEditor('sub', null, {{ $recordId }})">Add subcategory</button>
        @endif
        @if($canEdit)
            <button type="button" role="menuitem" x-on:click="$refs.menu.hidePopover(); $wire.openCategoryEditor('{{ $level }}', {{ $recordId }})">Edit category</button>
            <button type="button" role="menuitem" x-bind:disabled="busy" x-on:click="busy=true; $refs.menu.hidePopover(); $wire.toggleCategoryStatus('{{ $level }}', {{ $recordId }}).finally(()=>busy=false)">{{ $isActive ? 'Deactivate' : 'Activate' }}</button>
        @endif
        @if($canDelete)
            <button type="button" role="menuitem" class="is-danger" x-bind:disabled="busy" x-on:click="busy=true; $refs.menu.hidePopover(); $wire.deleteCategory('{{ $level }}', {{ $recordId }}).finally(()=>busy=false)">Delete permanently</button>
        @endif
    </div>
</div>
