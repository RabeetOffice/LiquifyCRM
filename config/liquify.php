<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Deploy hook
    |--------------------------------------------------------------------------
    | GitHub Actions calls POST /hooks/deploy/{token} after a release has been
    | switched live. The token must match this value exactly.
    */
    'deploy_hook_token' => env('DEPLOY_HOOK_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Release layout (Deployer)
    |--------------------------------------------------------------------------
    | On Hostinger the app lives in ~/crm/releases/<n> with ~/crm/current as a
    | symlink. Locally these paths do not exist and the rollback tools hide.
    */
    'deploy_path' => env('DEPLOY_PATH'),
    'version_file' => env('APP_VERSION_FILE', '.release'),

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'reporting_currency' => env('REPORTING_CURRENCY', 'GBP'),
    'default_currency' => env('DEFAULT_CURRENCY', 'GBP'),
    'default_timezone' => env('DEFAULT_TIMEZONE', 'Europe/London'),
    'reveal_limit_per_hour' => (int) env('REVEAL_LIMIT_PER_HOUR', 30),
    'invitation_expiry_days' => (int) env('INVITATION_EXPIRY_DAYS', 7),

    'currencies' => ['GBP', 'AUD', 'USD', 'EUR', 'CAD', 'NZD'],

    /*
    |--------------------------------------------------------------------------
    | Brand palette (Liquify Orange)
    |--------------------------------------------------------------------------
    | Exact values from the design section of the architecture document.
    */
    'palette' => [
        'primary' => [
            50 => '#FFF4ED',
            100 => '#FFE6D5',
            200 => '#FFCDAA',
            300 => '#FFAE78',
            400 => '#FF8A4C',
            500 => '#FF6B2C',
            600 => '#F7631B',
            700 => '#E04E0B',
            800 => '#B8400A',
            900 => '#94360C',
            950 => '#4F1906',
        ],
    ],
];
