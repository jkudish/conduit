<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | CLI Drivers
    |--------------------------------------------------------------------------
    |
    | Configuration for each CLI driver. These map to providers registered
    | with the Laravel AI SDK via AiManager::extend().
    |
    */
    'drivers' => [
        'claude' => [
            'binary' => env('CONDUIT_CLAUDE_BINARY', 'claude'),
            'model' => env('CONDUIT_CLAUDE_MODEL', 'claude-sonnet-4-5'),
            'max_turns' => (int) env('CONDUIT_CLAUDE_MAX_TURNS', 10),
            'timeout' => (int) env('CONDUIT_CLAUDE_TIMEOUT', 600),
            'allowed_tools' => [],
        ],

        'codex' => [
            'binary' => env('CONDUIT_CODEX_BINARY', 'codex'),
            'model' => env('CONDUIT_CODEX_MODEL', 'gpt-5.3-codex'),
            'timeout' => (int) env('CONDUIT_CODEX_TIMEOUT', 300),
        ],

        'amp' => [
            'binary' => env('CONDUIT_AMP_BINARY', 'amp'),
            'mode' => env('CONDUIT_AMP_MODE', 'smart'),
            'timeout' => (int) env('CONDUIT_AMP_TIMEOUT', 300),
            'allow_all_tools' => (bool) env('CONDUIT_AMP_ALLOW_ALL_TOOLS', true),
        ],

        'pi' => [
            'binary' => env('CONDUIT_PI_BINARY', 'pi'),
            'model' => env('CONDUIT_PI_MODEL', 'claude-sonnet-4-5'),
            'provider' => env('CONDUIT_PI_PROVIDER', 'anthropic'),
            'timeout' => (int) env('CONDUIT_PI_TIMEOUT', 600),
            'tools' => [],
        ],
    ],
];
