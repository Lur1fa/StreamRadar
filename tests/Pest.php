<?php

use App\Models\User;
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

function adminUser(array $attributes = []): User
{
    return User::factory()->admin()->create($attributes);
}

function regularUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}
