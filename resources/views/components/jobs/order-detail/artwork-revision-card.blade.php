@props([
    'revisionNote',
    'canExportDocument' => false,
])
@php
    $revisionComment = trim((string) data_get($revisionNote->meta, 'revision_comment', ''));
    if ($revisionComment === '') {
        $description = (string) $revisionNote->description;
        $revisionComment = str_contains($description, ': ')
            ? \Illuminate\Support\Str::after($description, ': ')
            : $description;
    }

    $referenceDocument = $revisionNote->relationLoaded('referenceDocument')
        ? $revisionNote->getRelation('referenceDocument')
        : null;
    $revisionDocuments = $revisionNote->relationLoaded('revisionDocuments')
        ? collect($revisionNote->getRelation('revisionDocuments'))->values()
        : ($referenceDocument ? collect([$referenceDocument]) : collect());
    $revisionAttachments = $revisionNote->relationLoaded('revisionAttachments')
        ? collect($revisionNote->getRelation('revisionAttachments'))->values()
        : collect();

    $revisionDocumentsById = $revisionDocuments->keyBy(fn($document) => (int) $document->id);
    $revisionAttachmentsById = $revisionAttachments->keyBy(fn($document) => (int) $document->id);
    $richText = app(\App\Services\RichTextService::class);

    $revisionItems = collect(data_get($revisionNote->meta, 'revision_items', []))
        ->map(fn($item) => (array) $item)
        ->filter(fn($item) => (int) ($item['document_id'] ?? 0) > 0)
        ->values();

    // Compatibility for revision activities created before per-artwork items
    // were persisted. A single older revision still renders as one paired row.
    if ($revisionItems->isEmpty()) {
        $revisionItems = $revisionDocuments->map(fn($document, $index) => [
            'document_id' => (int) $document->id,
            'document_name' => (string) $document->name,
            'comment' => $index === 0 ? $revisionComment : '',
            'revision_attachment_document_ids' => $index === 0
                ? $revisionAttachments->pluck('id')->map(fn($id) => (int) $id)->all()
                : [],
        ])->values();
    }
@endphp

<section class="ft-order-artwork-revision-card" wire:key="order-task-artwork-revision-{{ $revisionNote->id }}">
    <div class="ft-order-artwork-revision-head">
        <span class="ft-order-artwork-revision-alert" aria-hidden="true">
            <span>!</span>
        </span>

        <div class="ft-order-artwork-revision-heading">
            <div class="ft-order-artwork-revision-kicker">Revision required</div>
            <div class="ft-order-artwork-revision-title">Artwork revision issue</div>
            <div class="ft-order-artwork-revision-meta">
                Requested by {{ $revisionNote->user?->name ?? 'FlowTrack' }} · {{ \App\Support\UserLocalTime::format($revisionNote->created_at, 'M j, Y, g:i A') }}
            </div>
        </div>

        <span class="ft-order-artwork-revision-badge">Revision</span>
    </div>

    <div class="ft-order-artwork-revision-item-list">
        @forelse($revisionItems as $revisionItem)
            @php
                $revisionDocumentId = (int) ($revisionItem['document_id'] ?? 0);
                $revisionDocument = $revisionDocumentsById->get($revisionDocumentId);
                $revisionDocumentName = (string) ($revisionDocument?->name ?: ($revisionItem['document_name'] ?? 'Artwork'));
                $revisionExtension = strtoupper(pathinfo($revisionDocumentName, PATHINFO_EXTENSION) ?: 'FILE');
                $itemComment = trim((string) ($revisionItem['comment'] ?? ''));
                if ($itemComment === '' && $revisionItems->count() === 1) {
                    $itemComment = $revisionComment;
                }
                $requiredChangeText = $richText->withoutImages($itemComment);
                $requiredChangeImages = collect($richText->imageAttachments($itemComment));
                $itemAttachmentIds = collect($revisionItem['revision_attachment_document_ids'] ?? [])
                    ->map(fn($id) => (int) $id)
                    ->filter(fn($id) => $id > 0)
                    ->unique()
                    ->values();
                $itemAttachments = $itemAttachmentIds
                    ->map(fn($id) => $revisionAttachmentsById->get($id))
                    ->filter()
                    ->values();
            @endphp

            <div class="ft-order-artwork-revision-item" wire:key="order-artwork-revision-item-{{ $revisionNote->id }}-{{ $revisionDocumentId }}">
                <div class="ft-order-artwork-revision-item-artwork">
                    <div class="ft-order-artwork-revision-label">Artwork selected for revision</div>
                    <div class="ft-order-artwork-revision-attachment">
                        <x-ui.file-type-badge :extension="$revisionExtension" class="ft-order-artwork-revision-file-icon" />
                        <span class="ft-order-artwork-revision-file-copy">
                            <b>{{ $revisionDocumentName }}</b>
                            <small>{{ $revisionExtension }} · This artwork requires replacement</small>
                        </span>
                        @if($revisionDocument)
                            <span class="ft-order-artwork-revision-file-actions">
                                <a href="{{ route('documents.open', $revisionDocument) }}" target="_blank" rel="noopener">Open</a>
                                @if($canExportDocument)
                                    <span class="ft-order-artwork-revision-action-divider" aria-hidden="true"></span>
                                    <a href="{{ route('documents.download', $revisionDocument) }}">Download</a>
                                @endif
                            </span>
                        @endif
                    </div>
                </div>

                <div class="ft-order-artwork-revision-pair-grid">
                    <div class="ft-order-artwork-revision-field ft-order-artwork-revision-change-field">
                        <div class="ft-order-artwork-revision-label">Required change</div>
                        <div class="ft-order-artwork-revision-change ft-rich-text-content">
                            @if(filled($requiredChangeText))
                                <x-ui.mention-text :text="$requiredChangeText" />
                            @else
                                <span class="ft-order-artwork-revision-empty-copy">No written change provided.</span>
                            @endif
                        </div>
                    </div>

                    <div class="ft-order-artwork-revision-field ft-order-artwork-revision-supporting-field">
                        <div class="ft-order-artwork-revision-label">Supporting attachments</div>
                        @if($requiredChangeImages->isNotEmpty() || $itemAttachments->isNotEmpty())
                            <div class="ft-order-artwork-revision-reviewer-file-list">
                                @foreach($requiredChangeImages as $image)
                                    <div class="ft-order-artwork-revision-reviewer-file">
                                        <x-ui.file-type-badge :extension="$image['extension']" size="sm" />
                                        <span class="ft-order-artwork-revision-file-copy">
                                            <b>{{ $image['name'] }}</b>
                                            <small>{{ $image['extension'] }} · Reference</small>
                                        </span>
                                        <span class="ft-order-artwork-revision-file-actions">
                                            <a href="{{ $image['url'] }}" target="_blank" rel="noopener">Open</a>
                                            @if($canExportDocument)
                                                <span class="ft-order-artwork-revision-action-divider" aria-hidden="true"></span>
                                                <a href="{{ $image['download_url'] }}">Download</a>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach

                                @foreach($itemAttachments as $attachment)
                                    @php $attachmentExtension = strtoupper(pathinfo((string) $attachment->name, PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                                    <div class="ft-order-artwork-revision-reviewer-file">
                                        <x-ui.file-type-badge :extension="$attachmentExtension" size="sm" />
                                        <span class="ft-order-artwork-revision-file-copy">
                                            <b>{{ $attachment->name }}</b>
                                            <small>{{ $attachmentExtension }} · Revision reference</small>
                                        </span>
                                        <span class="ft-order-artwork-revision-file-actions">
                                            <a href="{{ route('documents.open', $attachment) }}" target="_blank" rel="noopener">Open</a>
                                            @if($canExportDocument)
                                                <span class="ft-order-artwork-revision-action-divider" aria-hidden="true"></span>
                                                <a href="{{ route('documents.download', $attachment) }}">Download</a>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="ft-order-artwork-revision-no-support">No supporting attachment</div>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="ft-order-artwork-revision-attachment ft-order-artwork-revision-attachment--empty">
                The artwork revision details are unavailable. Refresh the Order to load the current request.
            </div>
        @endforelse
    </div>
</section>
