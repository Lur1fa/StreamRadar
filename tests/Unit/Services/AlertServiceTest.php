<?php

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Services\AlertService;
use App\Services\Notifications\DeliveryFailed;
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
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withDiscord()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('sendNotifications uses the shared fallback format for Discord', function () {
    Http::fake();
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);
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
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);
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
    destination('email', ['to' => 'test@example.com']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withEmail()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Mail::assertSentCount(1);
});

test('sendNotifications sends Telegram when configured', function () {
    Http::fake();
    destination('telegram', ['chat_id' => '12345'], ['bot_token' => 'test-token']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withTelegram()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('sendNotifications sends webhook when configured', function () {
    Http::fake();
    destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://example.com/webhook']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withWebhook()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('sendNotifications sends JSON payload by default', function () {
    Http::fake();
    destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://example.com/webhook']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withWebhook()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => $request['event'] === 'stream.alerts.batch'
        && $request['count'] === 1);
});

test('JSON webhook ignores a custom notification template', function () {
    Http::fake();
    destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://example.com/webhook']);
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

test('sendNotifications sends plain text with ntfy headers to an ntfy destination', function () {
    Http::fake();
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'topic']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withNtfy()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://ntfy.example.com/topic'
        && ! $request->hasHeader('Authorization')
        && $request->hasHeader('Title')
        && $request->header('Title')[0] === '=?UTF-8?B?'.base64_encode("{$stream->user_name} is live").'?='
        && str_contains($request->header('Click')[0], 'twitch.tv')
        && str_contains($request->body(), $stream->title));
});

test('sendNotifications does not send Discord when no rule has notify_discord', function () {
    Http::fake();
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->create(['notify_discord' => false]);
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertNothingSent();
});

test('sendNotifications skips a disabled Discord destination', function () {
    Http::fake();
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test'], enabled: false);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withDiscord()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertNothingSent();
});

test('sendNotifications skips a disabled email destination', function () {
    Mail::fake();
    destination('email', ['to' => 'test@example.com'], enabled: false);
    $service = app(AlertService::class);

    $rule = AlertRule::factory()->withEmail()->create();
    $stream = Stream::factory()->create();

    $service->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Mail::assertNothingSent();
});

// Destinations

test('an ntfy access token is sent as a Bearer header and the title gets the prefix', function () {
    Http::fake();
    destination('ntfy', ['server_url' => 'https://ntfy.example.com/', 'topic' => 'alerts', 'title_prefix' => 'StreamRadar'], ['access_token' => 'tk_secret123']);
    $rule = AlertRule::factory()->withNtfy()->create();
    $stream = Stream::factory()->create(['user_name' => 'Streamer']);

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => $request->url() === 'https://ntfy.example.com/alerts'
        && $request->header('Authorization')[0] === 'Bearer tk_secret123'
        && $request->header('Title')[0] === '=?UTF-8?B?'.base64_encode('StreamRadar Streamer is live').'?=');
});

test('an ntfy user name and password are sent as Basic authentication; a token wins over them', function () {
    Http::fake();
    $destination = destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts', 'username' => 'phil'], ['password' => 'pa55word']);
    $rule = AlertRule::factory()->withNtfy()->create();
    $stream = Stream::factory()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);
    Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Basic '.base64_encode('phil:pa55word'));

    $destination->update(['secrets' => ['password' => 'pa55word', 'access_token' => 'tk_wins']]);
    Http::fake();
    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);
    Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer tk_wins');
});

test('a webhook uses its method, headers, query parameters and authentication', function () {
    Http::fake();
    destination('webhook', [
        'method' => 'PUT', 'auth' => 'basic', 'auth_username' => 'hook',
        'headers' => ['X-Api-Key'], 'query' => ['source'],
    ], [
        'url' => 'https://hooks.example.com/in?existing=1', 'auth_password' => 'hookpass',
        'headers' => ['X-Api-Key' => 'key-123'], 'query' => ['source' => 'stream radar'],
    ]);
    $rule = AlertRule::factory()->withWebhook()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create()]]);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://hooks.example.com/in?existing=1&source=stream%20radar'
        && $request->header('X-Api-Key')[0] === 'key-123'
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('hook:hookpass')
        && $request['event'] === 'stream.alerts.batch');
});

test('a webhook with a Bearer token sends it as the Authorization header', function () {
    Http::fake();
    destination('webhook', ['method' => 'POST', 'auth' => 'bearer'], ['url' => 'https://hooks.example.com/in', 'auth_token' => 'bearer-xyz']);
    $rule = AlertRule::factory()->withWebhook()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create()]]);

    Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer bearer-xyz');
});

test('an e-mail destination sends to all its addresses with its subject prefix', function () {
    Mail::fake();
    destination('email', ['to' => 'a@example.com, b@example.com', 'subject_prefix' => '[Live]']);
    $rule = AlertRule::factory()->withEmail()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create(['user_name' => 'Someone'])]]);

    Mail::assertSent(StreamAlertMail::class, fn (StreamAlertMail $mail) => $mail->hasTo('a@example.com') && $mail->hasTo('b@example.com')
        && str_starts_with($mail->envelope()->subject, '[Live] Someone is live'));
});

test('every enabled destination of a type receives the alert; other types and incomplete ones do not', function () {
    Http::fake();
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/1/one'], name: 'One');
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/2/two'], name: 'Two');
    destination('discord', [], [], name: 'Incomplete');
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts']);
    $rule = AlertRule::factory()->withDiscord()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create()]]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/1/one'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/2/two'));
});

test('deliver tries every message and then reports the refusal', function () {
    Http::fake(['*' => Http::response('forbidden', 403)]);
    $destination = destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'alerts']);
    $alerts = [
        ['rule' => AlertRule::factory()->withNtfy()->create(), 'stream' => Stream::factory()->create()],
        ['rule' => AlertRule::factory()->withNtfy()->create(), 'stream' => Stream::factory()->create()],
    ];

    expect(fn () => app(AlertService::class)->deliver($destination, $alerts))
        ->toThrow(DeliveryFailed::class, 'ntfy answered with HTTP 403 (forbidden).');
    Http::assertSentCount(2);
});

// Notification options: click action, attachment, action buttons

function ruleWithTemplate(string $channel, array $template): AlertRule
{
    return AlertRule::factory()->{'with'.$channel}()->create(['notification_template' => ['version' => 2, ...$template]]);
}

test('ntfy uses a custom click link with URL-encoded fields, the chosen attachment and action buttons', function () {
    Http::fake();
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'topic']);
    $rule = ruleWithTemplate('Ntfy', [
        'click' => 'custom',
        'click_url' => [['type' => 'text', 'value' => 'https://example.com/search?q='], ['type' => 'field', 'field' => 'title']],
        'attachment' => 'avatar',
        'actions' => [
            ['label' => [['type' => 'text', 'value' => 'Chat with '], ['type' => 'field', 'field' => 'streamer_name']],
                'url' => [['type' => 'text', 'value' => 'https://www.twitch.tv/popout/'], ['type' => 'field', 'field' => 'streamer_login'], ['type' => 'text', 'value' => '/chat']]],
            ['label' => [['type' => 'text', 'value' => 'Channel']], 'url' => [['type' => 'field', 'field' => 'url']]],
        ],
    ]);
    $stream = Stream::factory()->create([
        'user_login' => 'zoë_fan', 'user_name' => 'Zoë', 'title' => 'Ranked & chill',
        'profile_image_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/zoe-profile_image-300x300.png',
    ]);

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(function ($request) {
        $actions = json_decode($request->header('Actions')[0], true);

        return $request->header('Click')[0] === 'https://example.com/search?q=Ranked%20%26%20chill'
            && $request->header('Attach')[0] === 'https://static-cdn.jtvnw.net/jtv_user_pictures/zoe-profile_image-300x300.png'
            && $request->header('Filename')[0] === 'avatar.png'
            && mb_check_encoding($request->header('Actions')[0], 'ASCII')
            && $actions === [
                ['action' => 'view', 'label' => 'Chat with Zoë', 'url' => 'https://www.twitch.tv/popout/zo%C3%AB_fan/chat'],
                // Not a valid Twitch login, so the Twitch URL is Twitch's home page.
                ['action' => 'view', 'label' => 'Channel', 'url' => 'https://www.twitch.tv/'],
            ];
    });
});

test('ntfy sends no Click, Attach or Actions header when the rule turns them off', function () {
    Http::fake();
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'topic']);
    $rule = ruleWithTemplate('Ntfy', ['click' => 'none', 'attachment' => 'none']);

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create()]]);

    Http::assertSent(fn ($request) => ! $request->hasHeader('Click') && ! $request->hasHeader('Attach') && ! $request->hasHeader('Actions'));
});

test('an action button whose label comes out empty is left out', function () {
    Http::fake();
    destination('ntfy', ['server_url' => 'https://ntfy.example.com', 'topic' => 'topic']);
    $rule = ruleWithTemplate('Ntfy', ['actions' => [
        ['label' => [['type' => 'text', 'value' => 'Fine']], 'url' => [['type' => 'text', 'value' => 'https://example.com']]],
        ['label' => [['type' => 'field', 'field' => 'tags']], 'url' => [['type' => 'text', 'value' => 'https://example.com/tags']]],
    ]]);

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create(['tags' => null])]]);

    Http::assertSent(fn ($request) => json_decode($request->header('Actions')[0], true) === [
        ['action' => 'view', 'label' => 'Fine', 'url' => 'https://example.com'],
    ]);
});

test('Discord leaves out the link when clicking does nothing and shows the chosen attachment', function () {
    Http::fake();
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);
    $rule = ruleWithTemplate('Discord', ['click' => 'none', 'attachment' => 'box_art']);
    $stream = Stream::factory()->create(['game_box_art_url' => 'https://static-cdn.jtvnw.net/ttv-boxart/1_IGDB-{width}x{height}.jpg']);

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSent(fn ($request) => ! isset($request['embeds'][0]['url'])
        && ! isset($request['embeds'][0]['author']['url'])
        && $request['embeds'][0]['image']['url'] === 'https://static-cdn.jtvnw.net/ttv-boxart/1_IGDB-188x250.jpg'
        && ! isset($request['embeds'][0]['thumbnail']));
});

test('Telegram sends a text message when the rule attaches nothing', function () {
    Http::fake();
    destination('telegram', ['chat_id' => '12345'], ['bot_token' => '123456:ABCDEFGHIJKLMNOP']);
    $rule = ruleWithTemplate('Telegram', ['attachment' => 'none']);

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => Stream::factory()->create()]]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
});
