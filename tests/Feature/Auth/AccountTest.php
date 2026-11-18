<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a user can view their account page', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/account')->assertOk()->assertInertia(fn ($page) => $page->component('Account'));
});

test('a user can change their own password with the current one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->put('/account/password', [
        'current_password' => 'correct horse battery staple',
        'password' => 'a-brand-new-passphrase-99',
        'password_confirmation' => 'a-brand-new-passphrase-99',
    ])->assertRedirect();

    expect(Hash::check('a-brand-new-passphrase-99', $user->fresh()->password))->toBeTrue();
});

test('changing the password requires the correct current password', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->put('/account/password', [
        'current_password' => 'the-wrong-one',
        'password' => 'a-brand-new-passphrase-99',
        'password_confirmation' => 'a-brand-new-passphrase-99',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('correct horse battery staple', $user->fresh()->password))->toBeTrue();
});
