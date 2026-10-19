@props([
    'inputId',
    'model',
    'multiple' => false,
    'headline' => 'Drop files here',
    'browseText' => 'browse files',
    'helper' => null,
    'accept' => null,
    'progressLabel' => 'Uploading files...',
    'progressAriaLabel' => 'Attachment upload progress',
    'variant' => 'default',
    'browseButton' => null,
])
@php
    $accept = $accept ?: \App\Support\AttachmentUpload::accept();
    $helper = $helper ?: \App\Support\AttachmentUpload::helperText(20);
@endphp

<div
    {{ $attributes->class(['ft-create-attachment-uploader']) }}
    x-data="{
        uploading: false,
        progress: 0,
        hideTimer: null,
        startUpload() {
            if (this.hideTimer) window.clearTimeout(this.hideTimer);
            this.uploading = true;
            this.progress = 0;
        },
        updateUpload(event) {
            this.uploading = true;
            this.progress = Math.max(0, Math.min(100, Number(event.detail?.progress) || 0));
        },
        finishUpload() {
            this.progress = 100;
            this.hideTimer = window.setTimeout(() => {
                this.uploading = false;
                this.progress = 0;
            }, 350);
        },
        resetUpload() {
            if (this.hideTimer) window.clearTimeout(this.hideTimer);
            this.uploading = false;
            this.progress = 0;
        }
    }"
    x-on:livewire-upload-start="startUpload()"
    x-on:livewire-upload-progress="updateUpload($event)"
    x-on:livewire-upload-finish="finishUpload()"
    x-on:livewire-upload-error="resetUpload()"
    x-on:livewire-upload-cancel="resetUpload()"
>
    <label
        class="ft-create-attachment-dropzone ft-livewire-upload-zone {{ $variant === 'order-document' ? 'ft-create-attachment-dropzone--order-document' : '' }}"
        data-file-dropzone
        for="{{ $inputId }}"
    >
        <span class="ft-create-attachment-dropzone-icon" aria-hidden="true">
            @if($variant === 'order-document')
                <svg viewBox="0 0 24 24" fill="none"><path d="M7.7 18.5H6.5a4 4 0 0 1-.65-7.95A6.5 6.5 0 0 1 18.4 8.9a4.75 4.75 0 0 1-.9 9.6h-1.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M12 18V10.5m0 0-3 3m3-3 3 3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @else
                ⇧
            @endif
        </span>
        <span class="ft-create-attachment-dropzone-copy">
            @if($variant === 'order-document')
                <strong>{{ $headline }} <span>or <b>{{ $browseText }}</b></span></strong>
                <small data-drop-status>{{ $helper }}</small>
            @else
                <strong>{{ $headline }}</strong>
                <span class="ft-create-attachment-drop-or">or <b>{{ $browseText }}</b></span>
                <small data-drop-status>{{ $helper }}</small>
            @endif
        </span>
        @if($variant === 'order-document' && filled($browseButton))
            <span class="ft-create-attachment-browse-button" aria-hidden="true">{{ $browseButton }}</span>
        @endif
        <input
            id="{{ $inputId }}"
            type="file"
            wire:model="{{ $model }}"
            @if($multiple) multiple @endif
            accept="{{ $accept }}"
            hidden
        >
    </label>

    <div class="ft-create-attachment-progress" x-cloak x-show="uploading" x-transition.opacity.duration.120ms>
        <div class="ft-create-attachment-progress-meta">
            <span>{{ $progressLabel }}</span>
            <b x-text="`${Math.round(progress)}%`">0%</b>
        </div>
        <div
            class="ft-create-attachment-progress-track"
            role="progressbar"
            aria-label="{{ $progressAriaLabel }}"
            aria-valuemin="0"
            aria-valuemax="100"
            x-bind:aria-valuenow="Math.round(progress)"
        >
            <span x-bind:style="`width: ${progress}%`"></span>
        </div>
    </div>
</div>
