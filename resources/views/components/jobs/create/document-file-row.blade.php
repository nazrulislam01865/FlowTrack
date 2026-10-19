@props([
    'meta',
    'inputId',
    'index' => 0,
    'purchaseOrder' => false,
])

<div class="ft-order-document-file-row">
    <span class="ft-order-document-file-icon {{ $meta['icon_class'] }}" aria-hidden="true">{{ $meta['type_label'] }}</span>

    <span class="ft-order-document-file-copy">
        <strong title="{{ $meta['name'] }}">{{ $meta['name'] }}</strong>
        <small>{{ $purchaseOrder ? 'Purchase order' : ucfirst($meta['extension'] ?: 'Document') }} · {{ $meta['size_label'] }}</small>
    </span>

    <span class="ft-order-document-uploaded-badge">
        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.25" fill="currentColor" opacity=".14"/><path d="m5.4 8 1.65 1.65L10.8 5.9" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Uploaded
    </span>

    <span class="ft-order-document-file-actions">
        <button
            type="button"
            class="ft-order-document-file-action"
            data-local-file-action="preview"
            data-input-id="{{ $inputId }}"
            data-file-name="{{ $meta['name'] }}"
            data-file-size="{{ $meta['size'] }}"
        >Preview</button>

        @if($purchaseOrder)
            <label class="ft-order-document-file-action" for="{{ $inputId }}">Replace</label>
        @else
            <button
                type="button"
                class="ft-order-document-file-action"
                data-local-file-action="download"
                data-input-id="{{ $inputId }}"
                data-file-name="{{ $meta['name'] }}"
                data-file-size="{{ $meta['size'] }}"
            >Download</button>
        @endif

        <button
            type="button"
            class="ft-order-document-file-action is-remove"
            @if($purchaseOrder)
                wire:click="removeCreatePurchaseOrder"
                wire:target="removeCreatePurchaseOrder"
            @else
                wire:click="removeCreateAttachment({{ (int) $index }})"
                wire:target="removeCreateAttachment"
            @endif
            wire:loading.attr="disabled"
        >Remove</button>
    </span>
</div>
