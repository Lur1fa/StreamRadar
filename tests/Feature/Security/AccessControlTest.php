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

// The installation and its accounts: administrators only.
dataset('admin only', [
    ['get', '/settings/export'],
    ['post', '/settings/import'],
    ['post', '/settings/test-twitch'],
    ['get', '/users'],
    ['post', '/users'],
    ['put', '/users/sso'],
]);

// An account's own data: every signed-in account.
dataset('own data writes', [
    ['post', '/sync'],
    ['post', '/alerts'],
    ['post', '/blacklist'],
    ['delete', '/history'],
    ['put', '/settings'],
    ['post', '/settings/destinations'],
    ['post', '/tracking/channels'],
    ['post', '/tracking/categories'],
    ['post', '/tracking/tag-filters'],
]);

test('a regular user is forbidden from admin-only endpoints', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create());
    $this->{$method}($uri)->assertForbidden();
})->with('admin only');

test('guests cannot reach admin-only endpoints either', function (string $method, string $uri) {
    // Guests are redirected to login rather than being shown a 403.
    $response = $this->{$method}($uri);
    expect($response->getStatusCode())->toBe(302);
    $response->assertRedirect('/login');
})->with('admin only');

test('guests cannot change any account data', function (string $method, string $uri) {
    $this->{$method}($uri)->assertRedirect('/login');
})->with('own data writes');

test('a regular user is not forbidden from working on their own data', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create());

    // Empty requests fail validation or succeed, but are never refused for the role.
    expect($this->{$method}($uri)->getStatusCode())->not->toBe(403);
})->with('own data writes');

test('a regular user can open every page of their own data', function () {
    $this->actingAs(User::factory()->create());

    foreach (['/', '/tracking', '/alerts', '/blacklist', '/history', '/settings', '/account'] as $page) {
        $this->get($page)->assertOk();
    }
});

test('an admin can reach admin pages', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/settings')->assertOk();
    $this->get('/users')->assertOk();
});

test('an Inertia write that fails authorization returns a flash error, not a raw 403 page', function () {
    $this->actingAs(User::factory()->create());

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post('/users', ['name' => 'x'])
        ->assertRedirect();
});
