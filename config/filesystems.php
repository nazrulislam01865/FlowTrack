<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => env('FLOWTRACK_PUBLIC_STORAGE_PATH', storage_path('app/public')),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'flowtrack_private' => [
            'driver' => 'local',
            'root' => env('FLOWTRACK_PRIVATE_STORAGE_PATH', storage_path('app/flowtrack-private')),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'flowtrack_quarantine' => [
            'driver' => 'local',
            'root' => env('FLOWTRACK_QUARANTINE_STORAGE_PATH', storage_path('app/flowtrack-quarantine')),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Optional S3-compatible private object disks. They are not the default
        // because the shared-mount profile requires no additional PHP package.
        // To use these disks install league/flysystem-aws-s3-v3 and set
        // FLOWTRACK_DOCUMENT_DISK / FLOWTRACK_QUARANTINE_DISK accordingly.
        'flowtrack_object' => [
            'driver' => 's3',
            'key' => env('FLOWTRACK_OBJECT_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('FLOWTRACK_OBJECT_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('FLOWTRACK_OBJECT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('FLOWTRACK_OBJECT_BUCKET'),
            'url' => env('FLOWTRACK_OBJECT_URL'),
            'endpoint' => env('FLOWTRACK_OBJECT_ENDPOINT'),
            'use_path_style_endpoint' => env('FLOWTRACK_OBJECT_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'flowtrack_object_quarantine' => [
            'driver' => 's3',
            'key' => env('FLOWTRACK_OBJECT_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('FLOWTRACK_OBJECT_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('FLOWTRACK_OBJECT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('FLOWTRACK_OBJECT_QUARANTINE_BUCKET', env('FLOWTRACK_OBJECT_BUCKET')),
            'root' => env('FLOWTRACK_OBJECT_QUARANTINE_PREFIX', 'quarantine'),
            'url' => env('FLOWTRACK_OBJECT_URL'),
            'endpoint' => env('FLOWTRACK_OBJECT_ENDPOINT'),
            'use_path_style_endpoint' => env('FLOWTRACK_OBJECT_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => env('FLOWTRACK_PUBLIC_STORAGE_PATH', storage_path('app/public')),
    ],

];
