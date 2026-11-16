<?php

use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\Stream;
use App\Services\AlertService;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('an invalid stored template falls back to the default format instead of crashing', function () {
    Http::fake();
    Setting::set('webhook_url', 'https://ntfy.example.com/topic');
    Setting::set('webhook_format', 'ntfy');

    $rule = AlertRule::factory()->withWebhook()->create([
        'notification_template' => ['version' => 2, 'blocks' => 'broken'],
    ]);
    $stream = Stream::factory()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Http::assertSentCount(1);
});

test('one failing provider does not stop the others', function () {
    Http::fake();
    Mail::fake();

    // A webhook pointed at a private address will fail SSRF validation, but
    // e-mail must still go out.
    Setting::set('webhook_url', 'http://127.0.0.1/hook');
    Setting::set('mail_to', 'me@example.com');
    Setting::set('smtp_host', 'smtp.example.com');

    $rule = AlertRule::factory()->withWebhook()->withEmail()->create();
    $stream = Stream::factory()->create();

    app(AlertService::class)->sendNotifications([['rule' => $rule, 'stream' => $stream]]);

    Mail::assertSentCount(1);
});

test('a notification failure never aborts the sync bookkeeping', function () {
    $this->mock(TwitchApiService::class, function ($mock) {
        $mock->shouldReceive('getAllStreamsForCategory')->andReturn([]);
        $mock->shouldReceive('getStreamsByUsers')->andReturn([]);
        $mock->shouldReceive('getUsersByIds')->andReturn([]);
    });

    $this->mock(AlertService::class, function ($mock) {
        $mock->shouldReceive('sendNotifications')->andThrow(new RuntimeException('provider exploded'));
    });

    app(SyncService::class)->sync();

    expect(Setting::get('last_sync_at'))->not->toBeNull();
    expect(HistoryEvent::where('type', 'sync_completed')->exists())->toBeTrue();
});
