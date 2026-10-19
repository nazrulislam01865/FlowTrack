@props([
    'document',
    'canExport' => false,
])

@if($document)
    @php
        $version = max(1, (int) $document->version);
        $fileSize = (int) ($document->size ?? 0);
        $fileSizeLabel = $fileSize > 0
            ? ($fileSize >= 1048576
                ? number_format($fileSize / 1048576, 1).' MB'
                : number_format($fileSize / 1024, 0).' KB')
            : 'PDF';
    @endphp

    <div class="ft-production-artwork-pdf" wire:key="production-artwork-pdf-{{ $document->id }}">
        <div class="ft-production-artwork-pdf__icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M8 15h8M8 18h5"></path></svg>
        </div>

        <div class="ft-production-artwork-pdf__copy">
            <div class="ft-production-artwork-pdf__eyebrow">PRODUCTION REFERENCE</div>
            <div class="ft-production-artwork-pdf__title-row">
                <h3>Confirmed artwork &amp; tracking PDF</h3>
                <span class="ft-production-artwork-pdf__ready"><span aria-hidden="true">✓</span> Ready</span>
            </div>
            <p>Generated when Artwork was confirmed. Use this approved PDF as the production reference.</p>
            <div class="ft-production-artwork-pdf__meta">
                <span>Artwork V{{ $version }}</span>
                <i aria-hidden="true"></i>
                <span>{{ $fileSizeLabel }}</span>
                <i aria-hidden="true"></i>
                <span class="ft-production-artwork-pdf__filename" title="{{ $document->name }}">{{ $document->name }}</span>
            </div>
        </div>

        <div class="ft-production-artwork-pdf__actions">
            <a href="{{ route('documents.open', $document) }}" target="_blank" rel="noopener" class="ft-production-artwork-pdf__view">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                <span>View PDF</span>
            </a>
            @if($canExport)
                <a href="{{ route('documents.download', $document) }}" class="ft-production-artwork-pdf__download">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Download</span>
                </a>
            @endif
        </div>
    </div>

    <style>
        .ft-order-prototype-detail .ft-production-artwork-pdf {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            margin: 0;
            padding: 14px 18px;
            border-top: 1px solid #dfe8ef;
            border-bottom: 1px solid #dfe8ef;
            background: linear-gradient(90deg, #f0fbf8 0%, #f8fcfb 58%, #fff 100%);
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__icon {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #bde7dd;
            border-radius: 10px;
            background: #fff;
            color: #008a73;
            box-shadow: 0 3px 10px rgba(15, 23, 42, .04);
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__copy { min-width: 0; }
        .ft-order-prototype-detail .ft-production-artwork-pdf__eyebrow {
            margin-bottom: 3px;
            color: #07816d;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__title-row {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf h3 {
            margin: 0;
            color: #17233b;
            font-size: 14px;
            font-weight: 760;
            line-height: 1.25;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__ready {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #dff6ef;
            color: #087f6c;
            font-size: 9px;
            font-weight: 750;
            white-space: nowrap;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf p {
            margin: 3px 0 7px;
            color: #64748b;
            font-size: 10.5px;
            line-height: 1.4;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__meta {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 7px;
            color: #64748b;
            font-size: 9.5px;
            font-weight: 650;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__meta i {
            width: 3px;
            height: 3px;
            flex: 0 0 3px;
            border-radius: 999px;
            background: #94a3b8;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__filename {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__view,
        .ft-order-prototype-detail .ft-production-artwork-pdf__download {
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 8px;
            font-size: 10.5px;
            font-weight: 750;
            text-decoration: none;
            white-space: nowrap;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__view {
            border: 1px solid #008a73;
            background: #008a73;
            color: #fff;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__download {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
        }
        .ft-order-prototype-detail .ft-production-artwork-pdf__view:hover { background: #007a66; }
        .ft-order-prototype-detail .ft-production-artwork-pdf__download:hover { border-color: #94a3b8; background: #f8fafc; }

        @media (max-width: 900px) {
            .ft-order-prototype-detail .ft-production-artwork-pdf {
                grid-template-columns: auto minmax(0, 1fr);
            }
            .ft-order-prototype-detail .ft-production-artwork-pdf__actions {
                grid-column: 1 / -1;
                padding-left: 56px;
            }
        }
        @media (max-width: 620px) {
            .ft-order-prototype-detail .ft-production-artwork-pdf {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 14px;
            }
            .ft-order-prototype-detail .ft-production-artwork-pdf__icon { width: 38px; height: 38px; }
            .ft-order-prototype-detail .ft-production-artwork-pdf__actions {
                grid-column: auto;
                padding-left: 0;
            }
            .ft-order-prototype-detail .ft-production-artwork-pdf__view,
            .ft-order-prototype-detail .ft-production-artwork-pdf__download { flex: 1; }
            .ft-order-prototype-detail .ft-production-artwork-pdf__meta { flex-wrap: wrap; }
            .ft-order-prototype-detail .ft-production-artwork-pdf__filename { flex-basis: 100%; }
            .ft-order-prototype-detail .ft-production-artwork-pdf__meta i:last-of-type { display: none; }
        }
    </style>
@endif
