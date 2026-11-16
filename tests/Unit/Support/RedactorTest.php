<?php

use App\Models\Setting;
use App\Support\Redactor;

test('telegram bot tokens are removed from messages', function () {
    $msg = 'Failed: https://api.telegram.org/bot123456:ABCDEFghijklmnop/sendMessage timed out';
    $clean = Redactor::message($msg);

    expect($clean)->not->toContain('123456:ABCDEFghijklmnop')
        ->and($clean)->toContain('api.telegram.org');
});

test('URLs are reduced to scheme and host', function () {
    $clean = Redactor::message('posting to https://hooks.example.com/services/T000/B000/abcdefSECRET now');
    expect($clean)->not->toContain('abcdefSECRET')
        ->and($clean)->toContain('https://hooks.example.com');
});

test('bearer credentials are stripped', function () {
    expect(Redactor::message('Authorization: Bearer sk-abcdef123456'))->not->toContain('sk-abcdef123456');
});

test('known secret values are scrubbed wherever they appear', function () {
    Setting::set('smtp_password', 'p@ssw0rd-unique-value');

    expect(Redactor::message('SMTP auth failed with p@ssw0rd-unique-value'))
        ->not->toContain('p@ssw0rd-unique-value');
});

test('arrays are redacted by key pattern regardless of naming variations', function () {
    $out = Redactor::array([
        'twitch_client_secret' => 'xyz',
        'smtpPassword' => 'abc',
        'nested' => ['api_key' => 'k', 'authToken' => 't', 'safe' => 'keep'],
        'Webhook_URL' => 'https://x/y',
    ]);

    expect($out['twitch_client_secret'])->toBe(Redactor::REDACTED)
        ->and($out['smtpPassword'])->toBe(Redactor::REDACTED)
        ->and($out['nested']['api_key'])->toBe(Redactor::REDACTED)
        ->and($out['nested']['authToken'])->toBe(Redactor::REDACTED)
        ->and($out['nested']['safe'])->toBe('keep')
        ->and($out['Webhook_URL'])->toBe(Redactor::REDACTED);
});

test('known secret values inside array values are scrubbed', function () {
    Setting::set('twitch_client_secret', 'tw-unique-secret-123456');

    $out = Redactor::array(['note' => 'the secret is tw-unique-secret-123456 oops']);
    expect($out['note'])->not->toContain('tw-unique-secret-123456');
});
