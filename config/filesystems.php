<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Cloudflare R2 — primary media storage for all uploads.
        // Env uses AWS_* keys since the infra sprint wired them that way (FILESYSTEM_DISK=s3).
        // R2_* keys are accepted as fallback for new deployments.
        's3' => [
            'driver'                  => 's3',
            'key'                     => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret'                  => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region'                  => 'auto',
            'bucket'                  => env('R2_BUCKET', env('AWS_BUCKET')),
            'endpoint'                => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
            'url'                     => env('R2_PUBLIC_URL', env('AWS_URL')),
            'use_path_style_endpoint' => true,
            'throw'                   => true,
        ],

        // Named 'r2' alias — ImageUploadManager uses this disk name explicitly
        'r2' => [
            'driver'                  => 's3',
            'key'                     => env('R2_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret'                  => env('R2_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region'                  => 'auto',
            'bucket'                  => env('R2_BUCKET', env('AWS_BUCKET')),
            'endpoint'                => env('R2_ENDPOINT', env('AWS_ENDPOINT')),
            'url'                     => env('R2_PUBLIC_URL', env('AWS_URL')),
            'use_path_style_endpoint' => true,
            'throw'                   => true,
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
        public_path('storage') => storage_path('app/public'),
    ],

];
