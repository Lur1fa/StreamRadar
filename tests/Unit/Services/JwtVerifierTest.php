<?php

use App\Services\Auth\JwtVerifier;

function keypairAndJwk(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($res);

    return [$res, [
        'kty' => 'RSA', 'use' => 'sig', 'kid' => 'k1',
        'n' => JwtVerifier::base64UrlEncode($d['rsa']['n']),
        'e' => JwtVerifier::base64UrlEncode($d['rsa']['e']),
    ]];
}

test('a correctly signed RS256 token verifies', function () {
    [$key, $jwk] = keypairAndJwk();
    $v = new JwtVerifier;

    $header = JwtVerifier::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
    $payload = JwtVerifier::base64UrlEncode(json_encode(['sub' => 'abc']));
    openssl_sign("{$header}.{$payload}", $sig, $key, OPENSSL_ALGO_SHA256);
    $jwt = "{$header}.{$payload}.".JwtVerifier::base64UrlEncode($sig);

    $decoded = $v->decode($jwt);
    expect($v->verifyRsa($decoded['signing_input'], $decoded['signature'], $jwk, 'RS256'))->toBeTrue();
});

test('a token signed by a different key fails', function () {
    [$key, ] = keypairAndJwk();
    [, $otherJwk] = keypairAndJwk();
    $v = new JwtVerifier;

    $header = JwtVerifier::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
    $payload = JwtVerifier::base64UrlEncode(json_encode(['sub' => 'abc']));
    openssl_sign("{$header}.{$payload}", $sig, $key, OPENSSL_ALGO_SHA256);

    $decoded = $v->decode("{$header}.{$payload}.".JwtVerifier::base64UrlEncode($sig));
    expect($v->verifyRsa($decoded['signing_input'], $decoded['signature'], $otherJwk, 'RS256'))->toBeFalse();
});

test('tampered payload fails verification', function () {
    [$key, $jwk] = keypairAndJwk();
    $v = new JwtVerifier;

    $header = JwtVerifier::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
    $payload = JwtVerifier::base64UrlEncode(json_encode(['sub' => 'abc']));
    openssl_sign("{$header}.{$payload}", $sig, $key, OPENSSL_ALGO_SHA256);
    $tampered = JwtVerifier::base64UrlEncode(json_encode(['sub' => 'admin']));

    expect($v->verifyRsa("{$header}.{$tampered}", JwtVerifier::base64UrlDecode(JwtVerifier::base64UrlEncode($sig)), $jwk, 'RS256'))->toBeFalse();
});

test('base64url round-trips binary data', function () {
    $data = random_bytes(60);
    expect(JwtVerifier::base64UrlDecode(JwtVerifier::base64UrlEncode($data)))->toBe($data);
});

test('decode rejects a malformed token', function () {
    expect(fn () => (new JwtVerifier)->decode('only.two'))->toThrow(InvalidArgumentException::class);
});
