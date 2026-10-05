<?php

declare(strict_types=1);

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

        /*
         * NOT served. Laravel's default (`serve: true`) registers `GET` and `PUT /storage/{path}`, which answer any signed URL for a
         * file under this root, and this root contains the Resources store (`app/private/resources`). Nothing here mints such URLs
         * and the web server denies `/storage` before Laravel sees it, but a route that could serve a Resource file without asking
         * Resources has no place in the route table at all (ADR 0037, decision 66; a test pins its absence).
         */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Resources' managed files (ADR 0037, decision 63): the bytes of File Cards, named by storage key. PRIVATE and nothing
         * else: no `url`, no `serve`, so no route, link or signed URL can reach it; every download goes through an authorized
         * Resources route that resolves a Card first. Under `storage/`, which on the production host is the release-shared
         * `shared/storage`, outside the document root (ADR 0027) and in the backups (docs/runbooks/backup-and-restore.md).
         * `throw` so a failed write is never mistaken for a written file; links are skipped, never followed. Not read from the
         * environment: moving it is a reviewed change, and `security:production-check` fails if it is ever under public/.
         */
        'resources' => [
            'driver' => 'local',
            'root' => storage_path('app/private/resources'),
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'links' => 'skip',
            'throw' => true,
            'report' => false,
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
        public_path('storage') => storage_path('app/public'),
    ],

];
