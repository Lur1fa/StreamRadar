<?php

use App\Services\Http\OutboundUrlGuard;
use App\Services\Http\UnsafeUrlException;

function guard(array $map = [], array $exempt = []): OutboundUrlGuard
{
    // Default: every unknown host resolves to a public address.
    return new OutboundUrlGuard(fn (string $host) => $map[$host] ?? ['93.184.216.34'], $exempt);
}

test('a normal public https URL is allowed', function () {
    $validated = guard()->validate('https://example.com/webhook');
    expect($validated->host)->toBe('example.com')->and($validated->scheme)->toBe('https');
});

dataset('blocked literal addresses', [
    'loopback v4' => ['http://127.0.0.1/'],
    'loopback shorthand' => ['http://127.1/'],
    'all-zeros' => ['http://0.0.0.0/'],
    'private 10/8' => ['http://10.1.2.3/'],
    'private 192.168' => ['http://192.168.1.1/'],
    'private 172.16' => ['http://172.16.5.5/'],
    'link-local / cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'cgnat' => ['http://100.64.1.1/'],
    'ipv6 loopback' => ['http://[::1]/'],
    'ipv6 ULA' => ['http://[fd00::1]/'],
    'ipv6 link-local' => ['http://[fe80::1]/'],
    'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
    'decimal ip' => ['http://2130706433/'],
]);

test('literal private, loopback and reserved addresses are blocked', function (string $url) {
    expect(fn () => guard()->validate($url))->toThrow(UnsafeUrlException::class);
})->with('blocked literal addresses');

dataset('blocked hostnames', [
    'localhost' => ['http://localhost/'],
    'docker host' => ['http://host.docker.internal/'],
    'gcp metadata' => ['http://metadata.google.internal/'],
    '.local mDNS' => ['http://printer.local/'],
    '.internal' => ['http://db.internal/'],
    'single label' => ['http://intranet/'],
]);

test('internal hostnames are blocked before resolution', function (string $url) {
    expect(fn () => guard()->validate($url))->toThrow(UnsafeUrlException::class);
})->with('blocked hostnames');

test('DNS rebinding is defeated: a public name resolving to a private IP is blocked', function () {
    $g = guard(['evil.example.com' => ['10.0.0.5']]);
    expect(fn () => $g->validate('https://evil.example.com/'))->toThrow(UnsafeUrlException::class);
});

test('a name resolving to a mix of public and private is blocked (all answers vetted)', function () {
    $g = guard(['mixed.example.com' => ['93.184.216.34', '127.0.0.1']]);
    expect(fn () => $g->validate('https://mixed.example.com/'))->toThrow(UnsafeUrlException::class);
});

test('non-http schemes and embedded credentials are rejected', function () {
    expect(fn () => guard()->validate('ftp://example.com/'))->toThrow(UnsafeUrlException::class);
    expect(fn () => guard()->validate('file:///etc/passwd'))->toThrow(UnsafeUrlException::class);
    expect(fn () => guard()->validate('gopher://example.com/'))->toThrow(UnsafeUrlException::class);
    expect(fn () => guard()->validate('https://user:pass@example.com/'))->toThrow(UnsafeUrlException::class);
});

test('requireHttps blocks plain http', function () {
    expect(fn () => guard()->validate('http://example.com/', requireHttps: true))->toThrow(UnsafeUrlException::class);
    expect(guard()->validate('https://example.com/', requireHttps: true)->host)->toBe('example.com');
});

test('a host allowlist and path prefix are enforced (Discord)', function () {
    $ok = guard()->validate('https://discord.com/api/webhooks/1/tok', ['discord.com'], true, '/api/webhooks/');
    expect($ok->host)->toBe('discord.com');

    expect(fn () => guard()->validate('https://evil.com/api/webhooks/1/tok', ['discord.com'], true, '/api/webhooks/'))
        ->toThrow(UnsafeUrlException::class);
    expect(fn () => guard()->validate('https://discord.com/not-a-webhook', ['discord.com'], true, '/api/webhooks/'))
        ->toThrow(UnsafeUrlException::class);
});

test('an allow-listed host is exempt from the private-address check', function () {
    $g = guard(['ntfy.home.lan' => ['192.168.1.50']], ['ntfy.home.lan']);
    expect($g->validate('http://ntfy.home.lan/topic')->host)->toBe('ntfy.home.lan');
});

test('the allow-list does not weaken scheme or syntax checks', function () {
    $g = guard([], ['ntfy.home.lan']);
    expect(fn () => $g->validate('ftp://ntfy.home.lan/'))->toThrow(UnsafeUrlException::class);
});

test('malformed input and control characters are rejected', function () {
    expect(fn () => guard()->validate("https://example.com/\r\nHost: evil"))->toThrow(UnsafeUrlException::class);
    expect(fn () => guard()->validate('not a url'))->toThrow(UnsafeUrlException::class);
    expect(fn () => guard()->validate(''))->toThrow(UnsafeUrlException::class);
});
