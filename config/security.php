<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local (e-mail + password) login
    |--------------------------------------------------------------------------
    |
    | Disable only when OIDC is configured and you want SSO to be the sole
    | interactive login method. Console commands (user:create, user:password)
    | keep working so you can always recover access.
    |
    */

    'local_login' => filter_var(env('AUTH_LOCAL_LOGIN', true), FILTER_VALIDATE_BOOLEAN),

    'password_min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),

    /*
    |--------------------------------------------------------------------------
    | Login throttling / lockout
    |--------------------------------------------------------------------------
    */

    'login' => [
        // Hard cap per client IP within the decay window (all accounts).
        'ip_max_attempts' => (int) env('AUTH_LOGIN_IP_MAX_ATTEMPTS', 20),
        'ip_decay_seconds' => (int) env('AUTH_LOGIN_IP_DECAY_SECONDS', 900),
        // Per-account progressive lockout: after `lockout_threshold` failures the
        // account is locked for `lockout_base_seconds`, doubling on every further
        // failure up to `lockout_max_seconds`.
        'lockout_threshold' => (int) env('AUTH_LOGIN_LOCKOUT_THRESHOLD', 5),
        'lockout_base_seconds' => (int) env('AUTH_LOGIN_LOCKOUT_BASE_SECONDS', 60),
        'lockout_max_seconds' => (int) env('AUTH_LOGIN_LOCKOUT_MAX_SECONDS', 3600),
        'attempt_ttl_seconds' => 86400,
    ],

    // Lifetime of the one-time token printed by `php artisan auth:setup-token`.
    'setup_token_ttl_minutes' => (int) env('AUTH_SETUP_TOKEN_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Outbound webhook policy (SSRF protection)
    |--------------------------------------------------------------------------
    |
    | Webhook targets must use http/https, resolve only to public addresses
    | and are contacted with DNS pinning, no redirects, short timeouts and a
    | response size cap. Hosts listed in WEBHOOK_ALLOWED_HOSTS (exact,
    | case-insensitive, comma separated) are exempt from the private-address
    | check so that e.g. a self-hosted ntfy on the LAN can be targeted. Nothing
    | else is ever exempt.
    |
    */

    'webhook' => [
        'allowed_hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) env('WEBHOOK_ALLOWED_HOSTS', ''))))),
        'connect_timeout' => (int) env('WEBHOOK_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('WEBHOOK_TIMEOUT', 10),
        'max_response_bytes' => (int) env('WEBHOOK_MAX_RESPONSE_BYTES', 1048576),
        // Where a Discord webhook URL may point, for validation and for sending.
        'discord' => [
            'hosts' => ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'],
            'path_prefix' => '/api/webhooks/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote images embedded in notifications (e-mail, Discord, ntfy)
    |--------------------------------------------------------------------------
    */

    'image_host_suffixes' => ['jtvnw.net', 'twitch.tv'],

];
