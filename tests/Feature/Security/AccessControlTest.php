<?php

use App\Models\User;

beforeEach(function () {
    // Ensure "setup required" never short-circuits the guest redirect.
    User::factory()->admin()->create(['email' => 'root@example.com']);
});

test('the health check is public', function () {
    $this->get('/up')->assertOk();
});

dataset('protected pages', [
    ['get', '/'],
    ['get', '/tracking'],
    ['get', '/alerts'],
    ['get', '/blacklist'],
    ['get', '/history'],
    ['get', '/settings'],
    ['get', '/users'],
    ['get', '/account'],
    ['get', '/sync/status'],
    ['get', '/settings/export'],
]);

test('guests are redirected to login from every protected page', function (string $method, string $uri) {
    $this->{$method}($uri)->assertRedirect('/login');
})->with('protected pages');

dataset('admin only writes', [
    ['post', '/sync'],
    ['post', '/alerts'],
    ['post', '/blacklist'],
    ['delete', '/history'],
    ['put', '/settings'],
    ['get', '/settings'],
    ['get', '/settings/export'],
    ['post', '/settings/import'],
    ['post', '/settings/test-webhook'],
    ['get', '/users'],
    ['post', '/users'],
    ['get', '/tracking/search'],
    ['post', '/tracking/channels'],
]);

test('a regular user is forbidden from admin-only endpoints', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create());
    $this->{$method}($uri)->assertForbidden();
})->with('admin only writes');

test('guests cannot reach admin-only endpoints either', function (string $method, string $uri) {
    // Guests are redirected to login rather than being shown a 403.
    $response = $this->{$method}($uri);
    expect($response->getStatusCode())->toBe(302);
    $response->assertRedirect('/login');
})->with('admin only writes');

test('a regular user can view read-only pages', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertOk();
    $this->get('/alerts')->assertOk();
    $this->get('/history')->assertOk();
    $this->get('/account')->assertOk();
});

test('an admin can reach admin pages', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/settings')->assertOk();
    $this->get('/users')->assertOk();
});

test('an Inertia write that fails authorization returns a flash error, not a raw 403 page', function () {
    $this->actingAs(User::factory()->create());

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post('/alerts', ['name' => 'x'])
        ->assertRedirect();
});
