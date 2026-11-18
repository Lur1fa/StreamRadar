<?php

use App\Models\Setting;

test('a fresh installation scans every 5 minutes without seeding anything', function () {
    $this->artisan('app:setup')->assertSuccessful();

    expect(Setting::get('sync_frequency_minutes'))->toBeNull()
        ->and(Setting::syncFrequencyMinutes())->toBe(5);
});

test('does not overwrite existing settings', function () {
    Setting::set('sync_frequency_minutes', '15');

    $this->artisan('app:setup')->assertSuccessful();

    expect(Setting::get('sync_frequency_minutes'))->toBe('15')
        ->and(Setting::syncFrequencyMinutes())->toBe(15);
});
