<?php

/*
|--------------------------------------------------------------------------
| OpenID Connect (OIDC) single sign-on
|--------------------------------------------------------------------------
|
| OIDC is optional. It is considered *configured* only when OIDC_ENABLED is
| true AND issuer, client id and client secret are all present. Local
| password login stays available unless AUTH_LOCAL_LOGIN=false, so an
| unavailable identity provider never locks administrators out: a local
| admin account (created with `php artisan user:create --admin`) can always
| sign in, and all `user:*` console commands work without the IdP.
|
| Only the authorization-code flow with PKCE (S256) is supported. ID tokens
| must be signed with RS256/RS384/RS512 and are verified against the
| provider's JWKS. Issuer, audience, expiry and nonce are all checked.
|
*/

return [

    'enabled' => filter_var(env('OIDC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Issuer URL exactly as advertised by the provider, e.g.
    // https://auth.example.com/application/o/streamradar/ (Authentik) or
    // https://keycloak.example.com/realms/home (Keycloak).
    'issuer' => env('OIDC_ISSUER'),

    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),

    'scopes' => env('OIDC_SCOPES', 'openid profile email'),

    // Defaults to route('oidc.callback') (APP_URL + /auth/oidc/callback).
    'redirect_uri' => env('OIDC_REDIRECT_URI'),

    'button_label' => env('OIDC_BUTTON_LABEL', 'Sign in with SSO'),

    // Create a StreamRadar account on first successful SSO login. When false,
    // only accounts that already exist (created via CLI or the Users page and
    // matched by verified e-mail) may sign in through the provider.
    'auto_provision' => filter_var(env('OIDC_AUTO_PROVISION', true), FILTER_VALIDATE_BOOLEAN),

    // Link an SSO identity to an existing local account with the same e-mail
    // address. Only done when the provider asserts email_verified=true.
    'link_by_email' => filter_var(env('OIDC_LINK_BY_EMAIL', true), FILTER_VALIDATE_BOOLEAN),

    // Restrict sign-in to these e-mail domains (comma separated). Empty = any.
    'allowed_email_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('OIDC_ALLOWED_EMAIL_DOMAINS', ''))))),

    // Role mapping. A user becomes `admin` when the claim named by
    // OIDC_ADMIN_CLAIM (string or array, e.g. "groups") contains one of
    // OIDC_ADMIN_VALUES, or when their e-mail is listed in OIDC_ADMIN_EMAILS.
    // When either list is configured the role is re-evaluated on every login.
    'admin_claim' => env('OIDC_ADMIN_CLAIM', 'groups'),
    'admin_values' => array_values(array_filter(array_map('trim', explode(',', (string) env('OIDC_ADMIN_VALUES', ''))))),
    'admin_emails' => array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) env('OIDC_ADMIN_EMAILS', ''))))),

    // Only when explicitly enabled: the very first account ever created in
    // this installation becomes an administrator when it is provisioned via
    // SSO. Off by default because on a shared IdP anybody could race for it.
    'first_user_admin' => filter_var(env('OIDC_FIRST_USER_ADMIN', false), FILTER_VALIDATE_BOOLEAN),

    // Redirect to the provider's end_session_endpoint on logout when available.
    'rp_initiated_logout' => filter_var(env('OIDC_RP_LOGOUT', true), FILTER_VALIDATE_BOOLEAN),

    'http_timeout' => (int) env('OIDC_HTTP_TIMEOUT', 10),
    'discovery_ttl' => (int) env('OIDC_DISCOVERY_TTL', 3600),
    'clock_leeway' => 60,
    'allowed_algorithms' => ['RS256', 'RS384', 'RS512'],

];
