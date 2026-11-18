<?php

return [

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),
        'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'hot_url' => env('INERTIA_SSR_HOT_URL'),
        'timeout' => env('INERTIA_SSR_TIMEOUT'),
        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),
        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),
    ],

    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/pages'),
        ],
        'extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

    'expose_shared_prop_keys' => true,

    'store_previous_url' => false,

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inertia DevTools (hardened)
    |--------------------------------------------------------------------------
    |
    | The package default enables DevTools automatically whenever APP_ENV is
    | "local" and then skips its own authorization gate in that environment.
    | StreamRadar overrides both behaviours:
    |
    |  - DevTools are OFF unless INERTIA_DEVTOOLS_ENABLED=true is set explicitly.
    |  - The entry endpoints always run behind the web session, the `auth`
    |    middleware and the `admin` gate, regardless of environment.
    |  - Recorded payloads are additionally passed through
    |    App\Support\RedactingEntriesRepository, which redacts by key pattern
    |    and by known secret values (see App\Support\Redactor).
    |
    */

    'devtools' => [
        // Evaluated at config load, before any provider boots: DevTools can never
        // be switched on in production, whatever the environment variable says.
        'enabled' => filter_var(env('INERTIA_DEVTOOLS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && env('APP_ENV', 'production') !== 'production',
        'except' => ['telescope*', 'horizon*', '_inertia/devtools*', 'login', 'logout', 'setup', 'auth/*', 'settings*', 'users*', 'account*'],
        'storage' => [
            'path' => storage_path('inertia-devtools'),
            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),
            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),
            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),
        ],
        'middleware' => ['web', 'auth', 'can:admin'],
        'gate' => 'admin',
        'redact' => [
            'keys' => [
                'password',
                'password_confirmation',
                'current_password',
                'token',
                '_token',
                'access_token',
                'refresh_token',
                'id_token',
                'secret',
                'client_secret',
                'api_key',
                'twitch_client_secret',
                'smtp_password',
                'secrets',
                'bot_token',
                'auth_token',
                'auth_password',
                'webhook_url',
                'setup_token',
                'code_verifier',
            ],
            'headers' => [
                'cookie',
                'set-cookie',
                'authorization',
                'proxy-authorization',
                'x-xsrf-token',
                'x-csrf-token',
            ],
        ],
    ],

    'preserve_big_integers' => (bool) env('INERTIA_PRESERVE_BIG_INTEGERS', false),

];
