@props(['title', 'subtitle' => null, 'saveLabel' => 'Apply', 'saveAction'])
<div class="ft-product-bulk-modal-layer" role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <button type="button" class="ft-product-bulk-modal-backdrop" wire:click="closeProductBulkPanel" aria-label="Close"></button>
    <div class="ft-product-bulk-modal-card">
        <header>
            <div>
                <h2>{{ $title }}</h2>
                @if($subtitle)<p>{{ $subtitle }}</p>@endif
            </div>
            <button type="button" class="ft-product-bulk-modal-close" wire:click="closeProductBulkPanel" aria-label="Close">×</button>
        </header>
        <div class="ft-product-bulk-modal-body">{{ $slot }}</div>
        <footer>
            <button type="button" class="ft-product-page-btn is-secondary" wire:click="closeProductBulkPanel">Cancel</button>
            <button type="button" class="ft-product-page-btn is-primary" wire:click="{{ $saveAction }}" wire:loading.attr="disabled">{{ $saveLabel }}</button>
        </footer>
    </div>
</div>
