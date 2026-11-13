<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Filesystem disk
    |--------------------------------------------------------------------------
    |
    | Select a disk defined in your application's filesystems configuration.
    | Leave this unset or use null to follow Laravel's default disk. Existing
    | files keep the disk recorded in their saved metadata.
    |
    */

    'filesystem' => env('FILOQUENT_FILESYSTEM'),

    /*
    |--------------------------------------------------------------------------
    | Upload base path
    |--------------------------------------------------------------------------
    |
    | New uploads use this relative directory on the selected filesystem disk.
    | Changing this path does not move existing files or rewrite their saved
    | metadata. The default directory is "assets".
    |
    */

    'base_path' => env('FILOQUENT_BASE_PATH', 'assets'),

    /*
    |--------------------------------------------------------------------------
    | Redis upload recovery
    |--------------------------------------------------------------------------
    |
    | Reserve a standalone Redis logical database for upload recovery. Set its
    | nonnegative number, including zero, to enable the journal. Leave the
    | database unset or use null to disable it. Redis Cluster is unsupported.
    |
    */

    'redis' => [
        'database' => env('FILOQUENT_REDIS_DB'),

        /*
        |----------------------------------------------------------------------
        | Base Redis connection
        |----------------------------------------------------------------------
        |
        | Select an existing connection from your application's database.redis
        | configuration. Filoquent reuses its server and credentials through a
        | private connection with the journal database selected above.
        |
        */

        'connection' => env('FILOQUENT_REDIS_CONNECTION', 'default'),
    ],

];
