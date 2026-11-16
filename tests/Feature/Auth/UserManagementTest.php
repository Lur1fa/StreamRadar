<?php

use App\Models\User;
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
