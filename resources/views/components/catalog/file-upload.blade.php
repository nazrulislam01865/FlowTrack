@props([
    'model',
    'label',
    'hint',
    'accept' => null,
    'upload' => null,
    'current' => null,
    'clearAction' => null,
    'removeCurrentAction' => null,
])
@php
    $inputId = 'ft-product-upload-'.str_replace(['.', '[', ']'], '-', $model);
    $selectedName = $upload?->getClientOriginalName();
    $currentName = data_get($current, 'label');
    $displayName = $selectedName ?: $currentName;
    $hasFile = filled($displayName);
@endphp
<div
    class="ft-friendly-upload"
    x-data="{
        dragging: false,
        previewUrl: null,
        localName: @js($selectedName ?: ''),
        setFile(file) {
            if (!file) return;
            if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = URL.createObjectURL(file);
            this.localName = file.name || '';
        },
        clearLocal() {
            if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
            this.localName = '';
            if (this.$refs.input) this.$refs.input.value = '';
        },
        previewLocal() {
            if (!this.previewUrl) return;
            window.open(this.previewUrl, '_blank', 'noopener,noreferrer');
        }
    }"
>
    <div class="ft-friendly-upload-heading">
        <span>{{ $label }}</span><em>Optional</em>
    </div>
    <label for="{{ $inputId }}" class="ft-friendly-upload-drop" :class="dragging ? 'is-dragging' : ''"
        x-on:dragover.prevent="dragging=true"
        x-on:dragleave.prevent="dragging=false"
        x-on:drop.prevent="dragging=false; if($event.dataTransfer.files.length){ const dt=new DataTransfer(); dt.items.add($event.dataTransfer.files[0]); $refs.input.files=dt.files; setFile(dt.files[0]); $refs.input.dispatchEvent(new Event('change',{bubbles:true})); }">
        <input
            x-ref="input"
            id="{{ $inputId }}"
            type="file"
            wire:model="{{ $model }}"
            @if($accept) accept="{{ $accept }}" @endif
            x-on:change="setFile($event.target.files?.[0])"
        >
        <span class="ft-friendly-upload-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 16V4m0 0L8 8m4-4 4 4"/><path d="M5 14v5h14v-5"/></svg>
        </span>
        <span class="ft-friendly-upload-copy">
            <b x-text="localName || @js($displayName ?: 'Drop file here or browse')">{{ $displayName ?: 'Drop file here or browse' }}</b>
            <small>{{ $hint }}</small>
        </span>
        <span class="ft-friendly-upload-button">{{ $hasFile ? 'Replace' : 'Choose file' }}</span>
    </label>

    <div class="ft-friendly-upload-meta">
        <span wire:loading wire:target="{{ $model }}" class="ft-upload-progress">Uploading…</span>

        <template x-if="previewUrl">
            <button type="button" class="ft-upload-meta-action" x-on:click="previewLocal()">Preview</button>
        </template>

        @if($selectedName && $clearAction)
            <button type="button" wire:click="{{ $clearAction }}" x-on:click="clearLocal()">Remove</button>
        @elseif($currentName)
            @if(data_get($current, 'url'))<a href="{{ data_get($current, 'url') }}" target="_blank" rel="noopener">Preview</a>@endif
            @if(data_get($current, 'download_url'))<a href="{{ data_get($current, 'download_url') }}">Download</a>@endif
            @if($removeCurrentAction)<button type="button" class="is-danger" wire:click="{{ $removeCurrentAction }}">Remove</button>@endif
        @else
            <span x-show="!localName">No file selected</span>
            @if($clearAction)<button type="button" x-show="localName" wire:click="{{ $clearAction }}" x-on:click="clearLocal()">Remove</button>@endif
        @endif
    </div>

    @if($errors->has($model))
        <b class="validation-error">{{ $errors->first($model) }}</b>
    @endif
</div>
