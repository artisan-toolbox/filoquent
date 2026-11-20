<?php

use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsRectorFix;

return [
    'ai' => [
        'providers' => [
            'commit_message' => env('MAINTAINER_AI_COMMIT_MESSAGE_PROVIDER', 'openai'),
            'release_type_suggestion' => env('MAINTAINER_AI_RELEASE_TYPE_SUGGESTION_PROVIDER', 'openai'),
            'release_notes' => env('MAINTAINER_AI_RELEASE_NOTES_PROVIDER', 'openai'),
            'release_changelog_update' => env('MAINTAINER_AI_RELEASE_CHANGELOG_UPDATE_PROVIDER', 'openai'),
        ],
        'models' => [
            'commit_message' => env('MAINTAINER_AI_COMMIT_MESSAGE_MODEL', 'gpt-6.2-sol'),
            'release_type_suggestion' => env('MAINTAINER_AI_RELEASE_TYPE_SUGGESTION_MODEL', 'gpt-6.2-sol'),
            'release_notes' => env('MAINTAINER_AI_RELEASE_NOTES_MODEL', 'gpt-6.2-sol'),
            'release_changelog_update' => env('MAINTAINER_AI_RELEASE_CHANGELOG_UPDATE_MODEL', 'gpt-6.2-sol'),
        ],
        'local_feedback_locale' => env('MAINTAINER_AI_LOCAL_FEEDBACK_LOCALE'),
        'project_locale' => env('MAINTAINER_AI_PROJECT_LOCALE', 'en'),
    ],
    'git' => [
        'diff' => [
            'output_format' => env('MAINTAINER_GIT_DIFF_OUTPUT_FORMAT', 'line_by_line'),
        ],
    ],
    'release' => [
        'workflow' => 'tests.yml',
    ],
    'quality' => [
        'fix' => [
            RunsRectorFix::class,
            RunsPintFix::class,
        ],
        'test' => [
            RunsPestCheck::class,
            RunsPintCheck::class,
            RunsPhpStanCheck::class,
        ],
        'pest' => [
            'parallel' => env('MAINTAINER_PEST_PARALLEL', PHP_OS_FAMILY !== 'Windows'),
            'processes' => (int) env('MAINTAINER_PEST_PROCESSES', 4),
        ],
        'phpstan' => [
            'memory_limit' => env('MAINTAINER_PHPSTAN_MEMORY_LIMIT', '2G'),
        ],
    ],
];
