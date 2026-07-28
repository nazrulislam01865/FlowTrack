<?php

return [
    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */
    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Avoid realpath() here: it returns false when the directory has not yet
    | been created (common after extracting a ZIP). Laravel's view commands
    | require a non-empty path even before the first view is compiled.
    |
    */
    'compiled' => env('VIEW_COMPILED_PATH') ?: storage_path('framework/views'),
];
