<?php

return [
    // Public Order Tracking / QR is enabled by default; use the env switch to disable if needed.
    'order_tracking_enabled' => env('FLOWTRACK_ORDER_TRACKING_ENABLED', true),
    'workspace_id' => (int) env('FLOWTRACK_WORKSPACE_ID', 1),
    'document_disk' => env('FLOWTRACK_DOCUMENT_DISK', 'flowtrack_private'),
    'quarantine_disk' => env('FLOWTRACK_QUARANTINE_DISK', 'flowtrack_quarantine'),
    'legacy_document_disks' => array_values(array_filter(array_map('trim', explode(',', env('FLOWTRACK_LEGACY_DOCUMENT_DISKS', 'public,local'))))),
    // External tools run only when a confirmed artwork PDF is created or repaired.
    // Set full executable paths on servers where PHP-FPM has a restricted PATH.
    'artwork_pdf_tools' => [
        'pdfinfo' => env('FLOWTRACK_ARTWORK_PDFINFO', 'pdfinfo'),
        'pdf_renderer' => env('FLOWTRACK_ARTWORK_PDF_RENDERER', 'pdftoppm'),
        'office_converter' => env('FLOWTRACK_ARTWORK_OFFICE_CONVERTER', 'soffice'),
        'image_converter' => env('FLOWTRACK_ARTWORK_IMAGE_CONVERTER', 'magick'),
    ],
    'artwork_chunk_upload' => [
        // 15MB keeps each request below the application's normal 20MB attachment
        // ceiling while cutting the number of requests for 100-400MB artwork.
        'chunk_bytes' => (int) env('FLOWTRACK_ARTWORK_CHUNK_BYTES', 15728640),
        // Three simultaneous chunks improves throughput without multiplying PHP
        // workers aggressively when several staff members upload at once.
        'concurrency' => (int) env('FLOWTRACK_ARTWORK_CHUNK_CONCURRENCY', 3),
        'retention_hours' => (int) env('FLOWTRACK_ARTWORK_CHUNK_RETENTION_HOURS', 6),
        'persistence_timeout_seconds' => (int) env('FLOWTRACK_ARTWORK_PERSISTENCE_TIMEOUT', 900),
    ],
    'upload_security' => [
        'scanner' => env('FLOWTRACK_MALWARE_SCANNER', 'basic'),
        'clamav_binary' => env('FLOWTRACK_CLAMAV_BINARY', 'clamdscan'),
        'scan_timeout_seconds' => (int) env('FLOWTRACK_UPLOAD_SCAN_TIMEOUT', 30),
        'large_artwork_scan_timeout_seconds' => (int) env('FLOWTRACK_ARTWORK_SCAN_TIMEOUT', 300),
        'max_file_bytes' => (int) env('FLOWTRACK_UPLOAD_MAX_BYTES', 52428800),
        'zip_max_entries' => (int) env('FLOWTRACK_ZIP_MAX_ENTRIES', 1000),
        'zip_max_uncompressed_bytes' => (int) env('FLOWTRACK_ZIP_MAX_UNCOMPRESSED_BYTES', 536870912),
        'zip_max_ratio' => (int) env('FLOWTRACK_ZIP_MAX_RATIO', 150),
        'quarantine_retention_hours' => (int) env('FLOWTRACK_QUARANTINE_RETENTION_HOURS', 72),
    ],
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'FlowTrack Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
