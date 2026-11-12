<?php

use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsRectorFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheckFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusTest;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVueTscCheck;

return [
    'ai' => [
        'providers' => [
            'commit_message' => env('MAINTAINER_AI_COMMIT_MESSAGE_PROVIDER', 'openai'),
            'release_type_suggestion' => env('MAINTAINER_AI_RELEASE_TYPE_SUGGESTION_PROVIDER', 'openai'),
            'release_notes' => env('MAINTAINER_AI_RELEASE_NOTES_PROVIDER', 'openai'),
            'release_changelog_update' => env('MAINTAINER_AI_RELEASE_CHANGELOG_UPDATE_PROVIDER', 'openai'),
        ],
    ],
    'git' => [
        'diff' => [
            'output_format' => env('MAINTAINER_GIT_DIFF_OUTPUT_FORMAT', 'line_by_line'),
        ],
    ],
    'quality' => [
        'fix' => [
            RunsRectorFix::class,
            RunsPintFix::class,
            RunsVitePlusCheckFix::class,
        ],
        'test' => [
            RunsPestCheck::class,
            RunsPintCheck::class,
            RunsVitePlusCheck::class,
            RunsVitePlusTest::class,
            RunsVueTscCheck::class,
            RunsPhpStanCheck::class,
        ],
        'pest' => [
            'parallel' => env('MAINTAINER_PEST_PARALLEL', false),
        ],
        'phpstan' => [
            'memory_limit' => env('MAINTAINER_PHPSTAN_MEMORY_LIMIT', '2G'),
        ],
    ],
];
