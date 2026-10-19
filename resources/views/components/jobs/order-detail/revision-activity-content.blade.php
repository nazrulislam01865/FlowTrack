@props(['activity'])
@php
    $richText = app(\App\Services\RichTextService::class);
    $revisionComment = trim((string) data_get($activity->meta, 'revision_comment', ''));

    // Older revision activities may not have the dedicated meta value. Their
    // description still contains the original rich text, so keep them usable.
    if ($revisionComment === '') {
        $revisionComment = (string) $activity->description;
    }

    $revisionInstruction = $richText->withoutImages($revisionComment);
    $revisionImages = collect($richText->imageAttachments($revisionComment));
@endphp

<div class="ft-revision-activity-content">
    @if(filled($revisionInstruction))
        <div class="ft-revision-activity-instruction ft-rich-text-content">
            <x-ui.mention-text :text="$revisionInstruction" />
        </div>
    @endif

    @if($revisionImages->isNotEmpty())
        <div class="ft-revision-activity-image-list">
            @foreach($revisionImages as $image)
                <div class="ft-revision-activity-image-row">
                    <a
                        class="ft-revision-activity-thumbnail"
                        href="{{ $image['url'] }}"
                        target="_blank"
                        rel="noopener"
                        aria-label="Open revision reference image {{ $loop->iteration }}"
                    >
                        <img src="{{ $image['url'] }}" alt="Revision reference image {{ $loop->iteration }}" loading="lazy">
                    </a>

                    <span class="ft-revision-activity-image-copy">
                        <b>Revision reference image {{ $loop->iteration }}</b>
                        <small>{{ $image['name'] }}</small>
                    </span>

                    <span class="ft-revision-activity-image-actions">
                        <a href="{{ $image['url'] }}" target="_blank" rel="noopener">Open</a>
                        <span aria-hidden="true"></span>
                        <a href="{{ $image['download_url'] }}">Download</a>
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>
