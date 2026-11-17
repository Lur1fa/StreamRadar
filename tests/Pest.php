<?php

use App\Models\NotificationDestination;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\Http\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // The test suite must never perform real DNS lookups. Every hostname
        // resolves to a public address here; tests that exercise the SSRF
        // guard construct their own guard with a custom resolver.
        app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (string $host) => ['93.184.216.34']));
    })
    ->in('Feature', 'Unit');

// Application behaviour suites run as a signed-in administrator. Access
// control itself is covered by tests/Feature/Auth and tests/Feature/Security.
uses()->beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
})->in('Feature/Controllers', 'Feature/Middleware', 'Feature/E2E');

/**
 * The account that factories give their data to unless told otherwise: the
 * signed-in user, else the first account, else a new one.
 */
function testOwner(): User
{
    return auth()->user() ?? User::query()->orderBy('id')->first() ?? User::factory()->create();
}

/** A setting of one account (default filters, appearance, alert destinations). */
function setAccountSetting(string $key, mixed $value, ?User $user = null): void
{
    UserSetting::set($user ?? testOwner(), $key, $value);
}

/** An alert destination of one account (ntfy, webhook, email, telegram, discord). */
function destination(string $type, array $config = [], array $secrets = [], ?User $owner = null, bool $enabled = true, ?string $name = null): NotificationDestination
{
    return NotificationDestination::createFor($owner ?? testOwner(), [
        'name' => $name ?? ucfirst($type),
        'type' => $type,
        'enabled' => $enabled,
        'config' => $config,
        'secrets' => $secrets,
    ]);
}

function getAccountSetting(string $key, mixed $default = null, ?User $user = null): mixed
{
    return UserSetting::get($user ?? testOwner(), $key, $default);
}

function adminUser(array $attributes = []): User
{
    return User::factory()->admin()->create($attributes);
}

function regularUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}
