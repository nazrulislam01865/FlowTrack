<?php

return [
    // Keep Livewire's temporary-upload gate format-neutral. Each component
    // validates its own permanent file types, including EPS / ESP attachments.
    // This prevents MIME guessing from rejecting those formats too early.
    'temporary_file_upload' => [
        // Large Artwork files require a larger temporary upload window.
        // Other upload types keep their own business validation limits.
        'rules' => ['required', 'file', 'max:409600'],

        // Prevent long 400MB Artwork uploads from being interrupted by the
        // default temporary upload throttling behaviour.
        'middleware' => [
            'throttle:240,1',
        ],

        // Allow enough time for large temporary uploads to complete.
        'max_upload_time' => 60,
    ],
    'navigate' => [
        'show_progress_bar' => false,
    ],

    // Central placeholder used only by page/section components that are safe
    // to defer or lazy-load. Route-sensitive Create/Detail/editor components
    // mount immediately and progressively load only their heavy inner sections.
    'component_placeholder' => 'livewire.shared.page-placeholder',

];
