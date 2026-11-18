<?php

use App\Models\Setting;
use App\Services\Mail\SmtpSettings;

test('the scan interval default is read from one place', function () {
    expect(Setting::syncFrequencyMinutes())->toBe(5)
        ->and((int) Setting::DEFAULTS['sync_frequency_minutes'])->toBe(5);

    Setting::set('sync_frequency_minutes', '20');
    expect(Setting::syncFrequencyMinutes())->toBe(20);
});

test('there is no mail server until an administrator enters one', function () {
    config(['mail.default' => 'log']);

    SmtpSettings::apply();

    expect(SmtpSettings::configured())->toBeFalse()
        ->and(config('mail.default'))->toBe('log');
});

test('a stored mail server is applied, with the defaults for what is left unset', function () {
    config(['mail.from.address' => 'env@example.com']);
    Setting::set('smtp_host', 'smtp.example.com');
    Setting::set('smtp_password', 's3cret-pass');

    SmtpSettings::apply();

    expect(SmtpSettings::configured())->toBeTrue()
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe('587')
        ->and(config('mail.mailers.smtp.password'))->toBe('s3cret-pass')
        ->and(config('mail.from.address'))->toBe('env@example.com')
        ->and(config('mail.from.name'))->toBe('StreamRadar');
});

test('the settings page shows the values the application uses', function () {
    $this->actingAs(adminUser())->get('/settings')->assertInertia(fn ($page) => $page
        ->where('settings.sync_frequency_minutes', '5')
        ->where('settings.smtp_host', null)
        ->where('settings.smtp_port', '587')
        ->where('emailAvailable', false)
        ->where('serverKeys', Setting::SERVER_KEYS)
    );
});
