@props([
    'name' => '',
    'extension' => null,
    'size' => 'md',
])
@php
    $ext = strtolower(trim((string) ($extension ?: pathinfo((string) $name, PATHINFO_EXTENSION) ?: 'file')));
    $label = strtoupper($ext ?: 'FILE');
    $group = match ($ext) {
        'pdf' => 'pdf',
        'jpg', 'jpeg' => 'jpg',
        'png', 'webp', 'gif', 'ico', 'bmp', 'svg' => 'image',
        'ai' => 'ai',
        'eps', 'esp', 'ps' => 'eps',
        'cdr' => 'cdr',
        'zip', 'rar', '7z' => 'archive',
        'doc', 'docx' => 'doc',
        'xls', 'xlsx', 'csv' => 'sheet',
        'ppt', 'pptx' => 'slide',
        'txt' => 'text',
        default => 'file',
    };
@endphp
<span {{ $attributes->class(['ft-file-type-badge', 'ft-file-type-badge--'.$group, 'ft-file-type-badge--'.$size]) }} aria-hidden="true">{{ $label }}</span>
