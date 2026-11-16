<?php

use App\Models\User;
use App\Services\Auth\JwtVerifier;
use App\Services\Auth\OidcException;
use App\Services\Auth\OidcService;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;

const ISSUER = 'https://idp.example.test';

function oidcKeypair(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($res);

    return [
        'private' => $res,
        'jwk' => [
            'kty' => 'RSA',
            'use' => 'sig',
            'kid' => 'test-key',
            'alg' => 'RS256',
            'n' => JwtVerifier::base64UrlEncode($details['rsa']['n']),
            'e' => JwtVerifier::base64UrlEncode($details['rsa']['e']),
        ],
    ];
}

function signIdToken(array $claims, $privateKey, string $kid = 'test-key'): string
{
    $header = JwtVerifier::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
    $payload = JwtVerifier::base64UrlEncode(json_encode($claims));
    openssl_sign("{$header}.{$payload}", $sig, $privateKey, OPENSSL_ALGO_SHA256);

    return "{$header}.{$payload}.".JwtVerifier::base64UrlEncode($sig);
}

function configureOidc(array $jwk, array $overrides = []): void
{
    config(array_merge([
        'oidc.enabled' => true,
        'oidc.issuer' => ISSUER,
        'oidc.client_id' => 'streamradar',
        'oidc.client_secret' => 'super-secret-value',
        'oidc.auto_provision' => true,
    ], $overrides));

    Http::fake([
        ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => ISSUER,
            'authorization_endpoint' => ISSUER.'/authorize',
            'token_endpoint' => ISSUER.'/token',
            'jwks_uri' => ISSUER.'/jwks',
            'userinfo_endpoint' => ISSUER.'/userinfo',
            'end_session_endpoint' => ISSUER.'/logout',
        ]),
        ISSUER.'/jwks' => Http::response(['keys' => [$jwk]]),
    ]);
}

function freshStore(): Store
{
    $store = new Store('test-session', new ArraySessionHandler(120));
    $store->start();

    return $store;
}

/**
 * Drive the full flow and return the resulting claims/identity handling.
 */
function driveOidc(Store $store, $privateKey, array $claimOverrides = [], ?array $tokenResponse = null): OidcService
{
    $service = app(OidcService::class);

    $authReq = Request::create('/auth/oidc/redirect', 'GET');
    $authReq->setLaravelSession($store);
    $url = $service->buildAuthorizationUrl($authReq);
    parse_str(parse_url($url, PHP_URL_QUERY), $q);

    $flow = $store->get('oidc_flow');
    $claims = array_merge([
        'iss' => ISSUER,
        'aud' => 'streamradar',
        'sub' => 'subject-123',
        'exp' => time() + 300,
        'iat' => time(),
        'nonce' => $flow['nonce'],
        'email' => 'alice@example.com',
        'email_verified' => true,
        'name' => 'Alice',
    ], $claimOverrides);

    $idToken = signIdToken($claims, $privateKey);
    Http::fake(array_merge([
        ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => ISSUER, 'authorization_endpoint' => ISSUER.'/authorize',
            'token_endpoint' => ISSUER.'/token', 'jwks_uri' => ISSUER.'/jwks',
            'userinfo_endpoint' => ISSUER.'/userinfo', 'end_session_endpoint' => ISSUER.'/logout',
        ]),
        ISSUER.'/jwks' => Http::response(['keys' => [app('oidc_test_jwk')]]),
        ISSUER.'/token' => Http::response($tokenResponse ?? ['id_token' => $idToken, 'access_token' => 'at', 'token_type' => 'Bearer']),
    ]));

    $store->put('__state', $q['state']);

    return $service;
}

test('the OIDC routes are hidden when OIDC is not configured', function () {
    config(['oidc.enabled' => false]);
    $this->get('/auth/oidc/redirect')->assertNotFound();
    $this->get('/auth/oidc/callback')->assertNotFound();
});

test('a complete OIDC login provisions and signs in a user', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk']);

    $store = freshStore();
    $service = driveOidc($store, $kp['private']);

    $callback = Request::create('/auth/oidc/callback', 'GET', [
        'state' => $store->get('__state'),
        'code' => 'auth-code',
    ]);
    $callback->setLaravelSession($store);

    $identity = $service->handleCallback($callback);
    $user = $service->resolveUser($identity);

    expect($identity->email)->toBe('alice@example.com')
        ->and($identity->subject)->toBe('subject-123')
        ->and($user->oidc_issuer)->toBe(ISSUER)
        ->and($user->role)->toBe('user');
});

test('a tampered ID token signature is rejected', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk']);

    $store = freshStore();
    $service = app(OidcService::class);
    $authReq = Request::create('/auth/oidc/redirect', 'GET');
    $authReq->setLaravelSession($store);
    $service->buildAuthorizationUrl($authReq);
    $flow = $store->get('oidc_flow');

    // Sign with a *different* key than the one published in the JWKS.
    $attacker = oidcKeypair();
    $idToken = signIdToken([
        'iss' => ISSUER, 'aud' => 'streamradar', 'sub' => 'evil', 'exp' => time() + 300,
        'iat' => time(), 'nonce' => $flow['nonce'], 'email' => 'evil@example.com', 'email_verified' => true,
    ], $attacker['private']);

    Http::fake([
        ISSUER.'/.well-known/openid-configuration' => Http::response(['issuer' => ISSUER, 'authorization_endpoint' => ISSUER.'/authorize', 'token_endpoint' => ISSUER.'/token', 'jwks_uri' => ISSUER.'/jwks']),
        ISSUER.'/jwks' => Http::response(['keys' => [$kp['jwk']]]),
        ISSUER.'/token' => Http::response(['id_token' => $idToken]),
    ]);

    $callback = Request::create('/auth/oidc/callback', 'GET', ['state' => parseState($authReq, $service, $store), 'code' => 'c']);
    $callback->setLaravelSession($store);

    expect(fn () => $service->handleCallback($callback))->toThrow(OidcException::class);
});

test('a mismatched state is rejected (CSRF protection)', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk']);

    $store = freshStore();
    $service = app(OidcService::class);
    $authReq = Request::create('/auth/oidc/redirect', 'GET');
    $authReq->setLaravelSession($store);
    $service->buildAuthorizationUrl($authReq);

    $callback = Request::create('/auth/oidc/callback', 'GET', ['state' => 'not-the-real-state', 'code' => 'c']);
    $callback->setLaravelSession($store);

    expect(fn () => $service->handleCallback($callback))->toThrow(OidcException::class);
});

test('an expired ID token is rejected', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk']);

    $store = freshStore();
    $service = driveOidc($store, $kp['private'], ['exp' => time() - 3600]);

    $callback = Request::create('/auth/oidc/callback', 'GET', ['state' => $store->get('__state'), 'code' => 'c']);
    $callback->setLaravelSession($store);

    expect(fn () => $service->handleCallback($callback))->toThrow(OidcException::class);
});

test('an audience for a different client is rejected', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk']);

    $store = freshStore();
    $service = driveOidc($store, $kp['private'], ['aud' => 'some-other-client']);

    $callback = Request::create('/auth/oidc/callback', 'GET', ['state' => $store->get('__state'), 'code' => 'c']);
    $callback->setLaravelSession($store);

    expect(fn () => $service->handleCallback($callback))->toThrow(OidcException::class);
});

test('auto-provisioning can be turned off so only known accounts may sign in', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk'], ['oidc.auto_provision' => false, 'oidc.link_by_email' => false]);

    $store = freshStore();
    $service = driveOidc($store, $kp['private']);
    $callback = Request::create('/auth/oidc/callback', 'GET', ['state' => $store->get('__state'), 'code' => 'c']);
    $callback->setLaravelSession($store);

    $identity = $service->handleCallback($callback);
    expect(fn () => $service->resolveUser($identity))->toThrow(OidcException::class);
});

test('group claims can map a user to the admin role', function () {
    $kp = oidcKeypair();
    app()->instance('oidc_test_jwk', $kp['jwk']);
    configureOidc($kp['jwk'], ['oidc.admin_claim' => 'groups', 'oidc.admin_values' => ['streamradar-admins']]);

    $store = freshStore();
    $service = driveOidc($store, $kp['private'], ['groups' => ['streamradar-admins', 'everyone']]);
    $callback = Request::create('/auth/oidc/callback', 'GET', ['state' => $store->get('__state'), 'code' => 'c']);
    $callback->setLaravelSession($store);

    $user = $service->resolveUser($service->handleCallback($callback));
    expect($user->role)->toBe('admin');
});

function parseState(Request $authReq, OidcService $service, Store $store): string
{
    return $store->get('oidc_flow')['state'];
}
