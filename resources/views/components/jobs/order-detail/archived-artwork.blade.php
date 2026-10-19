@props([
    'documents' => collect(),
    'canExportDocument' => false,
])
@php
    $archivedDocuments = collect($documents)->values();
@endphp

@if($archivedDocuments->isNotEmpty())
    <section class="ft-order-archived-artwork" aria-labelledby="archived-artwork-title">
        <div class="ft-order-archived-artwork__head">
            <div class="ft-order-archived-artwork__title-row">
                <h3 id="archived-artwork-title">Archived Artwork</h3>
                <span class="ft-order-archived-artwork__count">{{ $archivedDocuments->count() }} in history</span>
            </div>
            <p>View previous versions of artwork that have been replaced. Cancelled artwork is retained here for audit history.</p>
        </div>

        <div class="ft-order-archived-artwork__table-scroll">
            <table class="ft-order-archived-artwork__table">
                <thead>
                    <tr>
                        <th scope="col">Filename</th>
                        <th scope="col">Version</th>
                        <th scope="col">Uploaded by</th>
                        <th scope="col">Uploaded on</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="ft-order-archived-artwork__action-heading">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($archivedDocuments as $document)
                        <tr wire:key="archived-artwork-document-{{ $document->id }}">
                            <td>
                                <div class="ft-order-archived-artwork__file">
                                    <x-ui.file-type-badge :name="$document->name" size="sm" />
                                    <div class="ft-order-archived-artwork__file-copy">
                                        <span class="ft-order-archived-artwork__filename" title="{{ $document->name }}">{{ $document->name }}</span>
                                        @if(filled($document->artwork_revision_reason))
                                            <div class="ft-order-archived-artwork__reason" title="{{ $document->artwork_revision_reason }}">
                                                <strong>{{ $document->artwork_archive_reason_label ?: 'Revision reason' }}</strong>
                                                <span>{{ \Illuminate\Support\Str::limit($document->artwork_revision_reason, 120) }}</span>
                                            </div>
                                        @endif
                                        @if(!empty($document->artwork_cancelled_product_names))
                                            <div class="ft-order-archived-artwork__reason ft-order-archived-artwork__reason--products">
                                                <strong>Removed product{{ count($document->artwork_cancelled_product_names) === 1 ? '' : 's' }}</strong>
                                                <span>{{ implode(', ', $document->artwork_cancelled_product_names) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="ft-order-archived-artwork__version">v{{ max(1, (int) $document->version) }}</td>
                            <td>{{ $document->relationLoaded('uploader') ? ($document->uploader?->name ?? 'FlowTrack') : 'FlowTrack' }}</td>
                            <td>{{ \App\Support\UserLocalTime::format($document->created_at, 'M j, Y \\a\\t g:i A') }}</td>
                            <td><span class="ft-order-archived-artwork__status {{ (string) ($document->artwork_archive_status ?: 'Archived') === 'Cancelled' ? 'is-cancelled' : '' }}">{{ $document->artwork_archive_status ?: 'Archived' }}</span></td>
                            <td>
                                <div class="ft-order-archived-artwork__actions">
                                    <a href="{{ route('documents.open', $document) }}" target="_blank" rel="noopener">Open</a>
                                    @if($canExportDocument)
                                        <a href="{{ route('documents.download', $document) }}">Download</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
