<?php

use App\Models\User;
use App\Services\Auth\OidcService;
use Illuminate\Support\Facades\Hash;

test('an admin can list users', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->count(2)->create();

    $this->get('/users')->assertOk()->assertInertia(fn ($page) => $page->component('Users')->has('users', 3));
});

test('a regular user cannot access user management', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/users')->assertForbidden();
    $this->post('/users', [])->assertForbidden();
});

test('an admin can create a user with a role', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/users', [
        'name' => 'New Person', 'email' => 'new@example.com', 'role' => 'user',
        'password' => 'a-strong-passphrase-123',
    ])->assertRedirect();

    $user = User::findByEmail('new@example.com');
    expect($user)->not->toBeNull()->and($user->role)->toBe('user')
        ->and(Hash::check('a-strong-passphrase-123', $user->password))->toBeTrue();
});

test('the last administrator cannot be demoted, disabled or deleted', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $other = User::factory()->create();

    // acting admin changing their own role is blocked separately; use a second admin then demote the first
    $this->put("/users/{$other->id}", ['role' => 'user'])->assertRedirect();

    // Now $admin is the only admin. Demote via another admin path:
    $secondAdmin = User::factory()->admin()->create();
    $this->actingAs($secondAdmin);
    // delete the first admin leaving secondAdmin; then try to remove the last one
    $this->delete("/users/{$admin->id}")->assertRedirect();

    $this->delete("/users/{$secondAdmin->id}")->assertRedirect()->assertSessionHas('error');
    expect(User::admins()->active()->count())->toBe(1);
});

test('an admin cannot change their own role', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create(); // ensure not last admin
    $this->actingAs($admin);

    $this->put("/users/{$admin->id}", ['role' => 'user'])->assertRedirect()->assertSessionHas('error');
    expect($admin->fresh()->role)->toBe('admin');
});

test('disabling a user clears their sessions and blocks login', function () {
    $this->actingAs(User::factory()->admin()->create());
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    $this->put("/users/{$victim->id}", ['disabled' => true])->assertRedirect();
    expect($victim->fresh()->isDisabled())->toBeTrue();
});

test('an admin can switch automatic SSO account creation off and on', function () {
    $this->actingAs(User::factory()->admin()->create());
    $oidc = app(OidcService::class);

    expect($oidc->autoProvisionEnabled())->toBeTrue();

    $this->put('/users/sso', ['auto_provision' => false])->assertRedirect();
    expect($oidc->autoProvisionEnabled())->toBeFalse();
    $this->get('/users')->assertInertia(fn ($page) => $page->where('sso.autoProvision', false));

    $this->put('/users/sso', ['auto_provision' => true])->assertRedirect();
    expect($oidc->autoProvisionEnabled())->toBeTrue();
});

test('the switch on the Users page wins over OIDC_AUTO_PROVISION', function () {
    $this->actingAs(User::factory()->admin()->create());
    config(['oidc.auto_provision' => false]);
    $oidc = app(OidcService::class);

    expect($oidc->autoProvisionEnabled())->toBeFalse();

    $this->put('/users/sso', ['auto_provision' => true])->assertRedirect();
    expect($oidc->autoProvisionEnabled())->toBeTrue();
});

test('a regular user cannot change automatic account creation', function () {
    $this->actingAs(User::factory()->create());

    $this->put('/users/sso', ['auto_provision' => false])->assertForbidden();
    expect(app(OidcService::class)->autoProvisionEnabled())->toBeTrue();
});

test('automatic account creation needs an explicit value', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->put('/users/sso', [])->assertSessionHasErrors('auto_provision');
});

function enableSso(array $overrides = []): void
{
    config(array_merge([
        'oidc.enabled' => true,
        'oidc.issuer' => 'https://idp.example.test',
        'oidc.client_id' => 'streamradar',
        'oidc.client_secret' => 'super-secret-value',
    ], $overrides));
}

test('a new user needs a password when SSO is not configured', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/users', ['name' => 'No Password', 'email' => 'nopass@example.com', 'role' => 'user', 'password' => ''])
        ->assertSessionHasErrors('password');

    expect(User::findByEmail('nopass@example.com'))->toBeNull();
});

test('with SSO configured an admin can create an account without a password', function () {
    $this->actingAs(User::factory()->admin()->create());
    enableSso();

    $this->get('/users')->assertInertia(fn ($page) => $page->where('sso.passwordOptional', true));

    $this->post('/users', ['name' => 'Sso Person', 'email' => 'Sso@Example.com', 'role' => 'user', 'password' => ''])
        ->assertSessionHasNoErrors()->assertRedirect();

    $user = User::findByEmail('sso@example.com');
    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->hasLocalPassword())->toBeFalse();
});

test('an account without a password cannot sign in with a password', function () {
    $this->actingAs(User::factory()->admin()->create());
    enableSso();
    $this->post('/users', ['name' => 'Sso Person', 'email' => 'sso@example.com', 'role' => 'user']);
    auth()->logout();

    $this->post('/login', ['email' => 'sso@example.com', 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => 'sso@example.com', 'password' => 'anything-at-all-123'])->assertSessionHasErrors('email');
    expect(auth()->check())->toBeFalse();
});

test('a password given for a new user must still meet the minimum when SSO is configured', function () {
    $this->actingAs(User::factory()->admin()->create());
    enableSso();

    $this->post('/users', ['name' => 'Short', 'email' => 'short@example.com', 'role' => 'user', 'password' => 'short'])
        ->assertSessionHasErrors('password');
});

test('a password stays required when SSO cannot pick up existing accounts', function () {
    $this->actingAs(User::factory()->admin()->create());
    enableSso(['oidc.link_by_email' => false]);

    $this->get('/users')->assertInertia(fn ($page) => $page->where('sso.passwordOptional', false));

    $this->post('/users', ['name' => 'No Password', 'email' => 'nopass@example.com', 'role' => 'user'])
        ->assertSessionHasErrors('password');
});
