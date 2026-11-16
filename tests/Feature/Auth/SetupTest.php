<?php

use App\Models\User;
use App\Services\Auth\SetupTokenService;

test('setup is required and shown when no accounts exist', function () {
    $this->get('/setup')->assertOk()->assertInertia(fn ($page) => $page->component('Setup'));
});

test('the login page redirects to setup when there are no accounts and no SSO', function () {
    $this->get('/login')->assertRedirect('/setup');
});

test('setup is unavailable once an account exists', function () {
    User::factory()->admin()->create();

    $this->get('/setup')->assertNotFound();
    $this->post('/setup', [])->assertNotFound();
});

test('creating the first admin requires a valid setup token', function () {
    $this->post('/setup', [
        'setup_token' => 'bogus',
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-strong-passphrase-123',
        'password_confirmation' => 'a-strong-passphrase-123',
    ])->assertSessionHasErrors('setup_token');

    expect(User::count())->toBe(0);
});

test('a valid setup token creates the first administrator and signs them in', function () {
    $token = app(SetupTokenService::class)->issue();

    $this->post('/setup', [
        'setup_token' => $token,
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'a-strong-passphrase-123',
        'password_confirmation' => 'a-strong-passphrase-123',
    ])->assertRedirect('/');

    $user = User::firstOrFail();
    expect($user->role)->toBe(User::ROLE_ADMIN)
        ->and($user->email)->toBe('admin@example.com')
        ->and(auth()->id())->toBe($user->id);
});

test('the setup token is one-time and cannot be reused', function () {
    $setup = app(SetupTokenService::class);
    $token = $setup->issue();

    $this->post('/setup', [
        'setup_token' => $token, 'name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'a-strong-passphrase-123', 'password_confirmation' => 'a-strong-passphrase-123',
    ])->assertRedirect('/');

    expect($setup->verify($token))->toBeFalse();
});

test('a password shorter than the minimum is rejected', function () {
    $token = app(SetupTokenService::class)->issue();

    $this->post('/setup', [
        'setup_token' => $token, 'name' => 'Admin', 'email' => 'admin@example.com',
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

test('the setup token verifies with a hash, never stored in the clear', function () {
    $setup = app(SetupTokenService::class);
    $token = $setup->issue();

    $stored = \App\Models\Setting::get('setup_token_hash');
    expect($stored)->not->toBe($token)->toBe(hash('sha256', $token));
});
