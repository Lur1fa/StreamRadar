<?php

use App\Services\Notifications\DestinationType;

test('every type has a rule flag and config keys that match its defaults', function () {
    expect(DestinationType::ruleFlags())->toBe(['notify_ntfy', 'notify_webhook', 'notify_email', 'notify_telegram', 'notify_discord']);

    foreach (DestinationType::cases() as $type) {
        expect($type->configKeys())->toBe(array_keys($type->defaultConfig()));
    }
});

test('config is cut down to the type and normalised', function () {
    expect(DestinationType::Webhook->normalizeConfig([
        'method' => 'PUT', 'auth' => 'basic', 'auth_username' => 42, 'headers' => ['X-A', 7, 'X-B'], 'query' => 'nope', 'evil' => 'x',
    ]))->toBe(['method' => 'PUT', 'auth' => 'basic', 'auth_username' => '42', 'headers' => ['X-A', 'X-B'], 'query' => ['nope']]);

    expect(DestinationType::Ntfy->normalizeConfig(['topic' => 'alerts', 'username' => ['not', 'scalar']]))
        ->toBe(['server_url' => '', 'topic' => 'alerts', 'username' => '', 'title_prefix' => '']);

    expect(DestinationType::Discord->normalizeConfig(['anything' => 'x']))->toBe([]);
});

test('the browser list carries what the pages need', function () {
    $types = DestinationType::forBrowser();

    expect(array_column($types, 'value'))->toBe(array_column(DestinationType::cases(), 'value'))
        ->and($types[1]['label'])->toBe('Webhook')
        ->and(json_encode($types[4]['defaultConfig']))->toBe('{}');
});

test('the shared patterns accept and reject what the forms did', function () {
    expect(preg_match(DestinationType::TELEGRAM_BOT_TOKEN, '123456789:ABCdefGHIjklMNO'))->toBe(1)
        ->and(preg_match(DestinationType::TELEGRAM_BOT_TOKEN, 'not-a-token'))->toBe(0)
        ->and(preg_match(DestinationType::TELEGRAM_CHAT_ID, '-1001234567890'))->toBe(1)
        ->and(preg_match(DestinationType::NTFY_TOPIC, 'my_topic-1'))->toBe(1)
        ->and(preg_match(DestinationType::NTFY_TOPIC, 'no spaces'))->toBe(0);
});
