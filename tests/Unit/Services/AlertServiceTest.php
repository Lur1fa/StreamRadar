<?php

use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Services\AlertService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// checkAlerts()

test('returns empty array when no active rules exist', function () {
    $service = app(AlertService::class);
    $stream = Stream::factory()->create();

    expect($service->checkAlerts($stream, null))->toBe([]);
});

test('returns empty when no rules match the stream', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->forStreamer('someone_else')->create();
    $stream = Stream::factory()->create(['user_login' => 'my_streamer']);

    expect($service->checkAlerts($stream, null))->toBe([]);
});

test('returns triggered alert when rule matches', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create();
    $stream = Stream::factory()->create();

    $result = $service->checkAlerts($stream, null);

    expect($result)->toHaveCount(1)
        ->and($result[0]['rule']->id)->toBe($rule->id)
        ->and($result[0]['stream']->id)->toBe($stream->id);
});

test('skips inactive rules', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->inactive()->create();
    $stream = Stream::factory()->create();

    expect($service->checkAlerts($stream, null))->toBe([]);
});

test('skips rule when old stream already matched', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create();
    $stream = Stream::factory()->create();
    $oldStream = clone $stream;

    expect($service->checkAlerts($stream, $oldStream))->toBe([]);
});

test('triggers rule when old stream did not match but new stream does', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create(['min_viewers' => 100]);
    $stream = Stream::factory()->withViewers(200)->create();
    $oldStream = clone $stream;
    $oldStream->viewer_count = 50;

    $result = $service->checkAlerts($stream, $oldStream);

    expect($result)->toHaveCount(1);
});

test('triggers when category changes and notify_on_category_change is enabled', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->withCategoryChange()->create();
    $stream = Stream::factory()->create(['game_name' => 'Fortnite']);
    $oldStream = clone $stream;
    $oldStream->game_name = 'Just Chatting';

    $result = $service->checkAlerts($stream, $oldStream);

    expect($result)->toHaveCount(1);
});

test('skips when category changes but notify_on_category_change is disabled', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create(['notify_on_category_change' => false]);
    $stream = Stream::factory()->create(['game_name' => 'Fortnite']);
    $oldStream = clone $stream;
    $oldStream->game_name = 'Just Chatting';

    $result = $service->checkAlerts($stream, $oldStream);

    expect($result)->toHaveCount(0);
});

test('skips new stream when notify_on_stream_start is false', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->create(['notify_on_stream_start' => false, 'notify_on_category_change' => true]);
    $stream = Stream::factory()->create();

    $result = $service->checkAlerts($stream, null);

    expect($result)->toHaveCount(0);
});

test('does not duplicate alert on stream start when both goes_live and category_change enabled', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->withCategoryChange()->create();
    $stream = Stream::factory()->create();

    $result = $service->checkAlerts($stream, null);

    expect($result)->toHaveCount(1);
});

test('first_time mode skips if tracking already exists', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->firstTimeOnly()->create();
    $stream = Stream::factory()->create();

    StreamAlertTracking::factory()->create([
        'alert_rule_id' => $rule->id,
        'stream_twitch_id' => $stream->twitch_id,
    ]);

    expect($service->checkAlerts($stream, null))->toBe([]);
});

test('first_time mode triggers on first occurrence', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->firstTimeOnly()->create();
    $stream = Stream::factory()->create();

    expect($service->checkAlerts($stream, null))->toHaveCount(1);
});

test('creates StreamAlertTracking record on trigger', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create();
    $stream = Stream::factory()->create();

    $service->checkAlerts($stream, null);

    $this->assertDatabaseHas('stream_alert_tracking', [
        'alert_rule_id' => $rule->id,
        'stream_twitch_id' => $stream->twitch_id,
    ]);
});

test('creates HistoryEvent of type alert_triggered on trigger', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->create();
    $stream = Stream::factory()->create();

    $service->checkAlerts($stream, null);

    $this->assertDatabaseHas('history_events', [
        'type' => 'alert_triggered',
        'stream_twitch_id' => $stream->twitch_id,
    ]);
});

test('silent mode creates tracking without triggered_at', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create();
    $stream = Stream::factory()->create();

    $result = $service->checkAlerts($stream, null, silent: true);

    expect($result)->toBe([]);

    $tracking = StreamAlertTracking::where('alert_rule_id', $rule->id)->first();
    expect($tracking)->not->toBeNull()
        ->and($tracking->triggered_at)->toBeNull();
});

test('silent mode does not create HistoryEvent', function () {
    $service = app(AlertService::class);
    AlertRule::factory()->create();
    $stream = Stream::factory()->create();

    $service->checkAlerts($stream, null, silent: true);

    expect(HistoryEvent::where('type', 'alert_triggered')->count())->toBe(0);
});

// seedTrackingForRule()

test('seedTrackingForRule creates tracking for matching streams', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->forStreamer('target')->create();

    Stream::factory()->create(['user_login' => 'target']);
    Stream::factory()->create(['user_login' => 'other']);

    $service->seedTrackingForRule($rule);

    expect(StreamAlertTracking::where('alert_rule_id', $rule->id)->count())->toBe(1);
});

test('seedTrackingForRule creates records without triggered_at', function () {
    $service = app(AlertService::class);
    $rule = AlertRule::factory()->create();
    Stream::factory()->create();

    $service->seedTrackingForRule($rule);

    $tracking = StreamAlertTracking::first();
    expect($tracking->triggered_at)->toBeNull();
});

// sendNotifications()

test('sendNotifications does nothing when array is empty', function () {
    Http::fake();
    Mail::fake();
    $service = app(AlertService::class);

    $service->sendNotifications([]);

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('sendNotifications sends Discord webhook when configured', function () {
    Http::fake();
    Setting::set('discord_webhook_url', 'https://discord.com/api/webhooks/test');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withDiscord()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('sendNotifications uses the shared fallback format for Discord', function () {
    Http::fake();
    Setting::set('discord_webhook_url', 'https://discord.com/api/webhooks/test');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withDiscord()->create(['name' => 'My rule']);
    $stream = Stream::factory()->create([
        'user_name' => 'Streamer',
        'title' => 'Playing now',
        'game_name' => 'Some Game',
        'viewer_count' => 42,
        'tags' => ['English', 'Ranked'],
    ]);

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => $request['embeds'][0]['description'] === "Playing now\nSome Game · 42 viewers\nTags: English, Ranked\nAlert: My rule");
});

test('sendNotifications renders a custom template for Discord', function () {
    Http::fake();
    Setting::set('discord_webhook_url', 'https://discord.com/api/webhooks/test');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withDiscord()->create([
        'notification_template' => [
            'version' => 1,
            'blocks' => [
                ['type' => 'text', 'value' => 'LIVE: '],
                ['type' => 'field', 'field' => 'title'],
            ],
        ],
    ]);
    $stream = Stream::factory()->create(['title' => 'Custom title']);

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => $request['embeds'][0]['description'] === 'LIVE: Custom title');
});

test('sendNotifications sends email when configured', function () {
    Mail::fake();
    Setting::set('mail_to', 'test@example.com');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withEmail()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Mail::assertSentCount(1);
});

test('sendNotifications sends Telegram when configured', function () {
    Http::fake();
    Setting::set('telegram_bot_token', 'test-token');
    Setting::set('telegram_chat_id', '12345');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withTelegram()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('sendNotifications sends webhook when configured', function () {
    Http::fake();
    Setting::set('webhook_url', 'https://example.com/webhook');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withWebhook()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('sendNotifications sends JSON payload by default', function () {
    Http::fake();
    Setting::set('webhook_url', 'https://example.com/webhook');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withWebhook()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => $request['event'] === 'stream.alerts.batch'
        && $request['count'] === 1);
});

test('JSON webhook ignores a custom notification template', function () {
    Http::fake();
    Setting::set('webhook_url', 'https://example.com/webhook');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withWebhook()->create([
        'notification_template' => [
            'version' => 1,
            'blocks' => [['type' => 'text', 'value' => 'Do not include this']],
        ],
    ]);
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => $request['event'] === 'stream.alerts.batch'
        && ! str_contains($request->body(), 'Do not include this'));
});

test('sendNotifications sends plain text with ntfy headers when format is ntfy', function () {
    Http::fake();
    Setting::set('webhook_url', 'https://ntfy.example.com/topic');
    Setting::set('webhook_format', 'ntfy');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withWebhook()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Title')
        && $request->header('Title')[0] === '=?UTF-8?B?'.base64_encode("{$stream->user_name} is live").'?='
        && str_contains($request->header('Click')[0], 'twitch.tv')
        && str_contains($request->body(), $stream->title));
});

test('sendNotifications does not send Discord when no rule has notify_discord', function () {
    Http::fake();
    Setting::set('discord_webhook_url', 'https://discord.com/api/webhooks/test');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->create(['notify_discord' => false]);
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertNothingSent();
});

test('sendNotifications skips Discord when global notifications_discord_enabled is 0', function () {
    Http::fake();
    Setting::set('discord_webhook_url', 'https://discord.com/api/webhooks/test');
    Setting::set('notifications_discord_enabled', '0');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withDiscord()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertNothingSent();
});

test('sendNotifications skips email when global notifications_email_enabled is 0', function () {
    Mail::fake();
    Setting::set('mail_to', 'test@example.com');
    Setting::set('notifications_email_enabled', '0');
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withEmail()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Mail::assertNothingSent();
});
