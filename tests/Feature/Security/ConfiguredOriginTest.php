<?php

use App\Models\User;
use App\Services\Auth\OidcService;

/*
 * Behind a TLS-terminating proxy the container is reached over plain HTTP,
 * possibly with an internal Host header. An https:// APP_URL must then decide
 * every URL the app hands out.
 */

const INTERNAL = 'http://10.0.0.5:8080';
const PUBLIC_URL = 'https://radar.example.test';

beforeEach(function () {
    User::factory()->admin()->create(['email' => 'root@example.com']);
});

test('redirects use an https APP_URL instead of the address the request came in on', function () {
    config(['app.url' => PUBLIC_URL]);

    $this->get(INTERNAL.'/tracking')->assertRedirect(PUBLIC_URL.'/login');
});

test('the page a guest wanted is remembered on the configured origin', function () {
    config(['app.url' => PUBLIC_URL]);

    $this->get(INTERNAL.'/tracking?tab=channels');

    $this->post(INTERNAL.'/login', ['email' => 'root@example.com', 'password' => 'correct horse battery staple'])
        ->assertRedirect(PUBLIC_URL.'/tracking?tab=channels');
});

test('the OIDC callback address is built from APP_URL', function () {
    config(['app.url' => PUBLIC_URL]);

    $this->get(INTERNAL.'/login')->assertOk();

    expect(app(OidcService::class)->redirectUri())
        ->toBe(PUBLIC_URL.'/auth/oidc/callback');
});

test('signing out sends the browser to the configured origin', function () {
    config(['app.url' => PUBLIC_URL]);

    $this->actingAs(User::first())
        ->post(INTERNAL.'/logout', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', PUBLIC_URL.'/login');
});

test('pagination links are built from APP_URL', function () {
    config(['app.url' => PUBLIC_URL]);

    $this->actingAs(User::first())
        ->get(INTERNAL.'/history')
        ->assertInertia(fn ($page) => $page->where('events.path', PUBLIC_URL.'/history'));
});

test('a port and a trailing slash in APP_URL are respected', function () {
    config(['app.url' => 'https://radar.example.test:8443/']);

    $this->get(INTERNAL.'/tracking')->assertRedirect('https://radar.example.test:8443/login');
});

test('an http APP_URL leaves the app reachable on any address', function () {
    config(['app.url' => 'http://localhost:8080']);

    $this->get(INTERNAL.'/tracking')->assertRedirect(INTERNAL.'/login');
});
