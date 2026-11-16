<?php

use App\Models\Setting;

test('seeds default settings', function () {
    $this->artisan('app:setup')->assertSuccessful();

    expect(Setting::get('sync_frequency_minutes'))->toBe('5');
});

test('does not overwrite existing settings', function () {
    Setting::set('sync_frequency_minutes', '15');

    $this->artisan('app:setup')->assertSuccessful();

    expect(Setting::get('sync_frequency_minutes'))->toBe('15');
});
