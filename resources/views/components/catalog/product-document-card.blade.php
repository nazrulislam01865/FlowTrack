@props(['title', 'document' => null, 'emptyLabel' => 'No file'])
<div {{ $attributes->class(['ft-product-document-card']) }}>
    <div class="ft-product-document-card-title">{{ $title }}</div>
    @if($document)
        <div class="ft-product-document-card-row">
            <span class="ft-product-document-icon {{ ($document['kind'] ?? '') === 'template' ? 'is-template' : '' }}">{{ ($document['kind'] ?? '') === 'template' ? 'AI' : 'PDF' }}</span>
            <div class="ft-product-document-card-copy">
                <strong title="{{ $document['label'] }}">{{ \Illuminate\Support\Str::limit($document['label'], 34) }}</strong>
                <small>{{ strtoupper(pathinfo($document['label'], PATHINFO_EXTENSION) ?: 'FILE') }}</small>
            </div>
            @if($document['url'] ?? null)<a href="{{ $document['url'] }}" target="_blank" rel="noopener">Open</a>@endif
            @if($document['download_url'] ?? null)<a href="{{ $document['download_url'] }}">Download</a>@endif
        </div>
    @else
        <div class="ft-product-document-empty">{{ $emptyLabel }}</div>
    @endif
</div>
