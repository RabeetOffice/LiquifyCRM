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

    // Two-factor is forced for Super Admins and Admins (document rule). Set the
    // env key to false locally to sign in without an authenticator app.
    'require_two_factor' => (bool) env('LIQUIFY_REQUIRE_TWO_FACTOR', true),

    // Sign-in security (office network, phones, blocked addresses). Set the env key to false
    // when an Admin has locked everybody out, sign in, fix the rules, then remove the line.
    'sign_in_rules' => (bool) env('LIQUIFY_SIGN_IN_RULES', true),

    // Behind a tunnel or proxy (ngrok, Cloudflare) the visitor's https address
    // arrives in forwarded headers. Empty on Hostinger; "*" on a local machine
    // behind a tunnel; or a comma-separated list of proxy addresses.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'currencies' => ['GBP', 'AUD', 'USD', 'EUR', 'CAD', 'NZD'],

    /*
    |--------------------------------------------------------------------------
    | Website leads
    |--------------------------------------------------------------------------
    | The signed entry point for liquify.php: a request older than the replay
    | window is refused, each website may send 120 requests a minute, and a
    | website with no lead for 48 hours turns amber.
    */
    'ingest' => [
        'replay_window' => (int) env('INGEST_REPLAY_WINDOW', 300),
        'rate_limit' => (int) env('INGEST_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta (Facebook Lead Ads and Messenger)
    |--------------------------------------------------------------------------
    | One Meta app for every brand. The secret signs incoming webhooks; the
    | verify token is any long random text you also enter in the Meta app.
    | login_config_id is the optional "Facebook Login for Business"
    | configuration; without it the classic permission list is requested.
    */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'login_config_id' => env('META_LOGIN_CONFIG_ID'),
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
        'rate_limit' => (int) env('META_WEBHOOK_RATE_LIMIT', 120),
    ],

    'sites' => [
        'quiet_hours' => (int) env('SITE_QUIET_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public pages (privacy policy, delete your data)
    |--------------------------------------------------------------------------
    | Who runs this installation and where privacy requests go. Both are shown
    | on /privacy and /data-deletion, the two addresses the Meta app links to.
    | Change "updated" whenever the wording of either page changes.
    */
    'legal' => [
        'name' => env('LIQUIFY_LEGAL_NAME', 'LiquifyCRM'),
        'email' => env('LIQUIFY_LEGAL_EMAIL', 'rabeet0846551@gmail.com'),
        'facebook_app' => env('LIQUIFY_LEGAL_FACEBOOK_APP', 'LiquifyCRM Leads'),
        'updated' => '2026-10-26',
    ],

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
