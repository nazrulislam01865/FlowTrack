@props(['title', 'document' => null, 'emptyLabel' => 'No file'])
@php
    $kind = (string) ($document['kind'] ?? '');
    $isCertificate = $kind === 'certificate';
    $extension = strtolower((string) pathinfo((string) ($document['label'] ?? ''), PATHINFO_EXTENSION));
    $fileType = match ($extension) {
        'pdf' => 'PDF',
        'doc', 'docx' => 'DOC',
        'xls', 'xlsx' => 'XLS',
        'csv' => 'CSV',
        'txt' => 'TXT',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'ico' => 'IMG',
        'zip' => 'ZIP',
        'ai', 'eps', 'esp', 'cdr' => 'AI',
        default => 'FILE',
    };
@endphp
<div {{ $attributes->class(['ft-product-document-card']) }}>
    <div class="ft-product-document-card-title">{{ $title }}</div>
    @if($document)
        <div class="ft-product-document-card-row">
            <span class="ft-product-document-icon {{ $kind === 'template' ? 'is-template' : '' }}">{{ $isCertificate ? $fileType : 'AI' }}</span>
            <div class="ft-product-document-card-copy">
                <strong title="{{ $document['label'] }}">{{ \Illuminate\Support\Str::limit($document['label'], 34) }}</strong>
                <small>{{ strtoupper($extension ?: 'FILE') }}</small>
            </div>
            @if($document['url'] ?? null)
                <a href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $isCertificate ? 'View Certificate & Test Report' : 'Open product template' }}" title="View in browser when supported">{{ $isCertificate ? 'View' : 'Open' }}</a>
            @endif
            @if($document['download_url'] ?? null)
                <a href="{{ $document['download_url'] }}" aria-label="Download {{ $title }}">Download</a>
            @endif
        </div>
    @else
        <div class="ft-product-document-empty">{{ $emptyLabel }}</div>
    @endif
</div>
