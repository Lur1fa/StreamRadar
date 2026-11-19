<?php

use App\Models\Setting;
use App\Services\TwitchApiService;

test('fails when Twitch API is not configured', function () {
    $this->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(false);
    });

    $this->artisan('streams:sync')
        ->assertFailed();
});

test('skips when auto_sync_enabled is disabled without --force', function () {
    Setting::set('auto_sync_enabled', '0');

    $this->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
    });

    $this->artisan('streams:sync')
        ->assertSuccessful();
});

test('runs sync with --force regardless of frequency', function () {
    Setting::set('last_sync_at', now()->toIso8601String());
    Setting::set('sync_frequency_minutes', '60');

    $this->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getAllStreamsForCategory')->andReturn([]);
        $mock->shouldReceive('getStreamsByUsers')->andReturn([]);
        $mock->shouldReceive('getUsersByIds')->andReturn([]);
    });

    $this->artisan('streams:sync --force')
        ->assertSuccessful();
});

function mockIdleTwitch(): void
{
    test()->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('getAllStreamsForCategory')->andReturn([]);
        $mock->shouldReceive('getStreamsByUsers')->andReturn([]);
        $mock->shouldReceive('getUsersByIds')->andReturn([]);
    });
}

test('skips until the frequency has passed since the last scan started', function () {
    Setting::set('sync_frequency_minutes', '5');
    Setting::set('last_sync_at', '2026-11-19T12:00:01+00:00');
    $this->travelTo(now()->parse('2026-11-19T12:04:59+00:00'));
    mockIdleTwitch();

    $this->artisan('streams:sync')
        ->expectsOutputToContain('Skipping')
        ->doesntExpectOutputToContain('Starting stream sync')
        ->assertSuccessful();
});

test('scans on the scheduler tick the frequency is reached, even if the last scan started late in its minute', function () {
    Setting::set('sync_frequency_minutes', '5');
    Setting::set('last_sync_at', '2026-11-19T12:00:01+00:00');
    $this->travelTo(now()->parse('2026-11-19T12:05:00+00:00'));
    mockIdleTwitch();

    $this->artisan('streams:sync')
        ->expectsOutputToContain('Starting stream sync')
        ->assertSuccessful();
});
