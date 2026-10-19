@props([
    'file' => null,
    'removeAction' => null,
    'title' => 'Selected file',
])

@if($file)
    @php
        $name = method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'Selected file';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = '';
        $size = null;
        try { $mime = (string) $file->getMimeType(); } catch (\Throwable $e) { $mime = ''; }
        try { $size = (int) $file->getSize(); } catch (\Throwable $e) { $size = null; }
        $isImage = str_starts_with($mime, 'image/') || in_array($extension, ['jpg','jpeg','png','gif','webp','bmp'], true);
        $previewUrl = null;
        if ($isImage && method_exists($file, 'temporaryUrl')) {
            try { $previewUrl = $file->temporaryUrl(); } catch (\Throwable $e) { $previewUrl = null; }
        }
        $sizeLabel = $size !== null
            ? ($size >= 1048576 ? number_format($size / 1048576, 1).' MB' : number_format(max(1, $size) / 1024, 0).' KB')
            : null;
        $typeLabel = $extension !== '' ? strtoupper($extension) : 'FILE';
    @endphp

    <div class="ft-finance-upload-preview">
        <div class="ft-finance-upload-thumb {{ $previewUrl ? 'has-image' : '' }}">
            @if($previewUrl)
                <img src="{{ $previewUrl }}" alt="Preview of {{ $name }}">
            @else
                <span>{{ $typeLabel }}</span>
            @endif
        </div>
        <div class="ft-finance-upload-meta">
            <strong>{{ $title }}</strong>
            <span title="{{ $name }}">{{ $name }}</span>
            @if($sizeLabel)<small>{{ $typeLabel }} · {{ $sizeLabel }}</small>@else<small>{{ $typeLabel }}</small>@endif
        </div>
        @if($removeAction)
            <button type="button" class="ft-finance-upload-remove" wire:click="{{ $removeAction }}" aria-label="Remove selected file">×</button>
        @endif
    </div>
@endif
