<?php

use App\Models\User;

/** Read the first "email" validation message out of the session in any shape. */
function loginEmailError(): string
{
    $errors = app('session.store')->get('errors');

    if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
        return (string) $errors->getBag('default')->first('email');
    }
    if (is_object($errors) && method_exists($errors, 'first')) {
        return (string) $errors->first('email');
    }
    if (is_array($errors)) {
        return (string) ($errors['email'][0] ?? ($errors['default']['email'][0] ?? ''));
    }

    return '';
}

test('GET /login renders the login page', function () {
    User::factory()->admin()->create();

    $this->get('/login')->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('Login')->where('localLogin', true));
});

test('valid credentials sign the user in', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct horse battery staple'])
        ->assertRedirect('/');

    expect(auth()->id())->toBe($user->id);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('invalid password is rejected without signing in', function () {
    User::factory()->create(['email' => 'user@example.com']);

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong-password-xyz'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

test('an empty or weak password no longer grants access', function () {
    // The legacy middleware let any non-empty value through once credentials existed.
    User::factory()->create(['email' => 'user@example.com']);

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'x'])->assertSessionHasErrors('email');
    expect(auth()->check())->toBeFalse();
});

test('error message does not reveal whether the account exists', function () {
    User::factory()->create(['email' => 'real@example.com']);

    $unknown = $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'whatever-123'])
        ->assertSessionHasErrors('email');
    $unknownMsg = session('errors')->get('email');
    session()->forget('errors');

    $wrong = $this->post('/login', ['email' => 'real@example.com', 'password' => 'whatever-123'])
        ->assertSessionHasErrors('email');
    $wrongMsg = session('errors')->get('email');

    expect($unknownMsg)->toBe($wrongMsg);
});

test('disabled accounts cannot sign in', function () {
    User::factory()->disabled()->create(['email' => 'off@example.com']);

    $this->post('/login', ['email' => 'off@example.com', 'password' => 'correct horse battery staple'])
        ->assertSessionHasErrors('email');
    expect(auth()->check())->toBeFalse();
});

test('an SSO-only account cannot sign in with a password', function () {
    User::factory()->sso()->create(['email' => 'sso@example.com']);

    $this->post('/login', ['email' => 'sso@example.com', 'password' => 'correct horse battery staple'])
        ->assertSessionHasErrors('email');
    expect(auth()->check())->toBeFalse();
});

test('repeated failures lock the account temporarily', function () {
    config(['security.login.lockout_threshold' => 3, 'security.login.ip_max_attempts' => 50]);
    User::factory()->create(['email' => 'target@example.com']);

    for ($i = 0; $i < 3; $i++) {
        $this->post('/login', ['email' => 'target@example.com', 'password' => 'nope-'.$i]);
    }

    // Even the correct password is now refused with a throttling message.
    $response = $this->post('/login', ['email' => 'target@example.com', 'password' => 'correct horse battery staple']);
    $response->assertSessionHasErrors('email');
    expect(loginEmailError())->toContain('Too many sign-in attempts');
    expect(auth()->check())->toBeFalse();
});

test('a per-IP cap blocks a spray across many accounts', function () {
    config(['security.login.ip_max_attempts' => 5, 'security.login.ip_decay_seconds' => 900, 'security.login.lockout_threshold' => 99]);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => "u{$i}@example.com", 'password' => 'nope']);
    }

    $response = $this->post('/login', ['email' => 'fresh@example.com', 'password' => 'nope']);
    $response->assertSessionHasErrors('email');
    expect(loginEmailError())->toContain('Too many sign-in attempts');
});

test('logout ends the session', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/logout')->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

test('local login can be disabled entirely', function () {
    config(['security.local_login' => false]);
    User::factory()->create(['email' => 'user@example.com']);

    $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct horse battery staple'])
        ->assertNotFound();
});
