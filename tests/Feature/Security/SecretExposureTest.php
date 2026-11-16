<?php

use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    Setting::set('twitch_client_secret', 'tw-secret-abcdef123456');
    Setting::set('smtp_password', 'smtp-pass-abcdef123456');
    setAccountSetting('telegram_bot_token', '123456:ABCDEFghijklmnopqrstuvwxyz0123456789');
    setAccountSetting('discord_webhook_url', 'https://discord.com/api/webhooks/42/zzSECRETtoken123');
    setAccountSetting('webhook_url', 'https://ntfy.example.com/secret-topic-xyz');
});

$secretValues = [
    'tw-secret-abcdef123456',
    'smtp-pass-abcdef123456',
    'ABCDEFghijklmnopqrstuvwxyz0123456789',
    'zzSECRETtoken123',
    'secret-topic-xyz',
];

test('the settings page never ships raw secret values', function () use ($secretValues) {
    $response = $this->get('/settings');
    $content = $response->getContent();

    foreach ($secretValues as $secret) {
        expect($content)->not->toContain($secret);
    }

    $response->assertInertia(fn ($page) => $page
        ->where('settings.twitch_client_secret_set', true)
        ->missing('settings.twitch_client_secret')
        ->missing('settings.smtp_password')
        ->missing('settings.telegram_bot_token')
        ->missing('settings.webhook_url')
    );
});

test('the export omits every secret', function () use ($secretValues) {
    $response = $this->get('/settings/export');
    $body = $response->getContent();

    foreach ($secretValues as $secret) {
        expect($body)->not->toContain($secret);
    }

    $data = $response->json();
    foreach (Setting::SECRET_KEYS as $key) {
        expect($data['settings'])->not->toHaveKey($key);
    }
});

test('no managed secret key is ever marked exportable', function () {
    foreach (Setting::SECRET_KEYS as $secretKey) {
        expect(Setting::exportableKeys())->not->toContain($secretKey);
    }
});

test('secret values are encrypted in the database', function () use ($secretValues) {
    $rows = \Illuminate\Support\Facades\DB::table('settings')->whereIn('key', Setting::SECRET_KEYS)->pluck('value');

    foreach ($rows as $stored) {
        foreach ($secretValues as $secret) {
            expect($stored)->not->toContain($secret);
        }
    }
});
