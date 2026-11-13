<?php

declare(strict_types=1);

return [

    'filesystem' => null,

    'base_path' => 'assets',

    'placeholder' => 'default',

    'redis' => [
        'database' => env('FILOQUENT_REDIS_DB'),
        'connection' => 'default',
    ],

];
