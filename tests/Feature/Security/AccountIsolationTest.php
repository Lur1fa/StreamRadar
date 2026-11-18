<?php

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\HistoryEvent;
use App\Models\NotificationDestination;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\Http\OutboundUrlGuard;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use App\Services\TwitchTrackerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Tracking, alerts, blacklist, history and streams belong to one account.
 * These tests use two ordinary accounts, Alice and Bob, and check that
 * neither can see, change or be affected by the other's data.
 */

beforeEach(function () {
    $this->alice = User::factory()->create(['email' => 'alice@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
});

function liveStream(array $overrides = []): array
{
    static $n = 0;
    $n++;

    return array_merge([
        'id' => (string) (900000 + $n),
        'user_id' => (string) (800000 + $n),
        'user_login' => 'live'.$n,
        'user_name' => 'Live'.$n,
        'game_id' => '777',
        'game_name' => 'Shared Game',
        'title' => 'A stream',
        'viewer_count' => 100,
        'language' => 'en',
        'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_x-{width}x{height}.jpg',
        'started_at' => now()->subHour()->toIso8601String(),
        'tags' => ['English'],
        'is_mature' => false,
    ], $overrides);
}

// ── Reading ──────────────────────────────────────────────────────────

test('every page shows only the signed-in account\'s data', function () {
    $aliceCategory = Category::factory()->for($this->alice, 'owner')->create(['name' => 'Alice Game']);
    $bobCategory = Category::factory()->for($this->bob, 'owner')->create(['name' => 'Bob Game']);
    Stream::factory()->for($this->alice, 'owner')->forCategory($aliceCategory)->create(['user_login' => 'alice_stream']);
    Stream::factory()->for($this->bob, 'owner')->forCategory($bobCategory)->create(['user_login' => 'bob_stream']);
    TrackedChannel::factory()->for($this->alice, 'owner')->create(['user_login' => 'alice_channel']);
    TrackedChannel::factory()->for($this->bob, 'owner')->create(['user_login' => 'bob_channel']);
    AlertRule::factory()->for($this->alice, 'owner')->create(['name' => 'Alice Alert']);
    AlertRule::factory()->for($this->bob, 'owner')->create(['name' => 'Bob Alert']);
    BlacklistRule::factory()->for($this->alice, 'owner')->create(['value' => 'alice_blocked']);
    BlacklistRule::factory()->for($this->bob, 'owner')->create(['value' => 'bob_blocked']);
    HistoryEvent::factory()->for($this->alice, 'owner')->create(['type' => 'stream_online', 'streamer_login' => 'alice_event']);
    HistoryEvent::factory()->for($this->bob, 'owner')->create(['type' => 'stream_online', 'streamer_login' => 'bob_event']);
    TagFilter::createFor($this->alice, ['tag' => 'alice-tag']);
    TagFilter::createFor($this->bob, ['tag' => 'bob-tag']);

    $this->actingAs($this->alice);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->has('streams', 1)
        ->where('streams.0.user_login', 'alice_stream')
        ->where('trackedLogins', ['alice_channel'])
        ->where('stats.categories_count', 1)
        ->where('stats.streams_count', 1)
        ->where('stats.alerts_count', 1)
        ->where('stats.blacklist_count', 1));

    $this->get('/tracking')->assertInertia(fn ($page) => $page
        ->has('categories', 1)->where('categories.0.name', 'Alice Game')
        ->has('channels', 1)->where('channels.0.user_login', 'alice_channel')
        ->has('tagFilters', 1)->where('tagFilters.0.tag', 'alice-tag'));

    $this->get('/alerts')->assertInertia(fn ($page) => $page
        ->has('alertRules', 1)->where('alertRules.0.name', 'Alice Alert')
        ->has('categories', 1)->where('categories.0.name', 'Alice Game'));

    $this->get('/blacklist')->assertInertia(fn ($page) => $page
        ->has('channels', 1)->where('channels.0.value', 'alice_blocked'));

    $this->get('/history')->assertInertia(fn ($page) => $page
        ->has('events.data', 1)->where('events.data.0.streamer_login', 'alice_event'));
});

test('a new account starts empty', function () {
    Category::factory()->for($this->alice, 'owner')->create();
    AlertRule::factory()->for($this->alice, 'owner')->create();

    $this->actingAs($this->bob);

    $this->get('/tracking')->assertInertia(fn ($page) => $page->has('categories', 0)->has('channels', 0));
    $this->get('/alerts')->assertInertia(fn ($page) => $page->has('alertRules', 0));
    $this->get('/')->assertInertia(fn ($page) => $page->has('streams', 0)->where('stats.categories_count', 0));
});

// ── Writing ──────────────────────────────────────────────────────────

test('another account\'s records cannot be changed or deleted and look like they do not exist', function () {
    $category = Category::factory()->for($this->alice, 'owner')->create(['is_active' => true]);
    $tagFilter = TagFilter::createFor($this->alice, ['tag' => 'retro', 'min_viewers' => 5]);
    $channel = TrackedChannel::factory()->for($this->alice, 'owner')->create(['user_login' => 'alice_channel']);
    $alert = AlertRule::factory()->for($this->alice, 'owner')->create(['name' => 'Alice Alert']);
    $blacklist = BlacklistRule::factory()->for($this->alice, 'owner')->create();

    $this->actingAs($this->bob);

    $this->put("/tracking/categories/{$category->id}", ['is_active' => false])->assertNotFound();
    $this->delete("/tracking/categories/{$category->id}")->assertNotFound();
    $this->post("/tracking/categories/{$category->id}/sync")->assertNotFound();
    $this->put("/tracking/tag-filters/{$tagFilter->id}", ['min_viewers' => 999])->assertNotFound();
    $this->delete("/tracking/tag-filters/{$tagFilter->id}")->assertNotFound();
    $this->delete("/tracking/channels/{$channel->id}")->assertNotFound();
    $this->put("/alerts/{$alert->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->delete("/alerts/{$alert->id}")->assertNotFound();
    $this->delete("/blacklist/{$blacklist->id}")->assertNotFound();

    // Removing a channel by name only looks at the signed-in account.
    $this->delete('/tracking/channels/by-login/alice_channel');

    expect($category->fresh()->is_active)->toBeTrue()
        ->and($tagFilter->fresh()->min_viewers)->toBe(5)
        ->and($channel->fresh())->not->toBeNull()
        ->and($alert->fresh()->name)->toBe('Alice Alert')
        ->and($blacklist->fresh())->not->toBeNull();
});

test('what an account creates belongs to that account, whatever the request says', function () {
    $this->actingAs($this->bob);

    $this->post('/tracking/categories', ['twitch_id' => '777', 'name' => 'Game', 'owner_id' => $this->alice->id])->assertSessionHasNoErrors();
    $this->post('/tracking/tag-filters', ['tag' => 'cozy', 'owner_id' => $this->alice->id])->assertSessionHasNoErrors();
    $this->post('/tracking/channels', ['user_login' => 'somebody', 'owner_id' => $this->alice->id]);
    $this->post('/alerts', ['name' => 'Mine', 'owner_id' => $this->alice->id])->assertSessionHasNoErrors();
    $this->post('/blacklist', ['type' => 'keyword', 'value' => 'spoilers', 'owner_id' => $this->alice->id])->assertSessionHasNoErrors();

    foreach ([Category::class, TagFilter::class, TrackedChannel::class, AlertRule::class, BlacklistRule::class] as $model) {
        expect($model::ownedBy($this->bob)->count())->toBe(1, $model)
            ->and($model::ownedBy($this->alice)->count())->toBe(0, $model);
    }
});

test('a record cannot be moved to another account by updating it', function () {
    $alert = AlertRule::factory()->for($this->bob, 'owner')->create();
    $category = Category::factory()->for($this->bob, 'owner')->create();

    $this->actingAs($this->bob);
    $this->put("/alerts/{$alert->id}", ['name' => 'Renamed', 'owner_id' => $this->alice->id]);
    $this->put("/tracking/categories/{$category->id}", ['is_active' => false, 'owner_id' => $this->alice->id]);

    expect($alert->fresh()->owner_id)->toBe($this->bob->id)
        ->and($category->fresh()->owner_id)->toBe($this->bob->id);
});

test('two accounts can track the same category, channel, tag and blacklist entry', function () {
    $this->actingAs($this->alice);
    $this->post('/tracking/categories', ['twitch_id' => '777', 'name' => 'Shared Game'])->assertSessionHasNoErrors();
    $this->post('/tracking/channels', ['user_login' => 'samechannel'])->assertSessionHasNoErrors();
    $this->post('/tracking/tag-filters', ['tag' => 'retro'])->assertSessionHasNoErrors();
    $this->post('/blacklist', ['type' => 'channel', 'value' => 'annoying'])->assertSessionHasNoErrors();

    $this->actingAs($this->bob);
    $this->post('/tracking/categories', ['twitch_id' => '777', 'name' => 'Shared Game'])->assertSessionHasNoErrors();
    $this->post('/tracking/channels', ['user_login' => 'samechannel'])->assertSessionHasNoErrors();
    $this->post('/tracking/tag-filters', ['tag' => 'retro'])->assertSessionHasNoErrors();
    $this->post('/blacklist', ['type' => 'channel', 'value' => 'annoying'])->assertSessionHasNoErrors();

    expect(Category::where('twitch_id', '777')->count())->toBe(2)
        ->and(TrackedChannel::where('user_login', 'samechannel')->count())->toBe(2)
        ->and(TagFilter::where('tag', 'retro')->count())->toBe(2)
        ->and(BlacklistRule::where('value', 'annoying')->count())->toBe(2);

    // ...but still not twice within one account.
    $this->post('/tracking/categories', ['twitch_id' => '777', 'name' => 'Shared Game'])->assertSessionHasErrors('twitch_id');
    $this->post('/tracking/tag-filters', ['tag' => 'retro'])->assertSessionHasErrors('tag');
});

test('an alert can only point at categories of the same account', function () {
    $aliceCategory = Category::factory()->for($this->alice, 'owner')->create();
    $bobCategory = Category::factory()->for($this->bob, 'owner')->create();

    $this->actingAs($this->bob);

    $this->post('/alerts', ['name' => 'Wrong', 'category_id' => $aliceCategory->id])->assertSessionHasErrors('category_id');

    $this->post('/alerts', ['name' => 'Mixed', 'category_ids' => [$aliceCategory->id, $bobCategory->id]])->assertSessionHasNoErrors();
    expect(AlertRule::ownedBy($this->bob)->where('name', 'Mixed')->first()->category_ids)->toBe([$bobCategory->id]);
});

test('clearing the history and blacklisting only affect the own account', function () {
    HistoryEvent::factory()->for($this->alice, 'owner')->count(2)->create();
    HistoryEvent::factory()->for($this->bob, 'owner')->count(3)->create();
    Stream::factory()->for($this->alice, 'owner')->create(['user_login' => 'loud']);
    Stream::factory()->for($this->bob, 'owner')->create(['user_login' => 'loud']);

    $this->actingAs($this->bob);
    $this->delete('/history');
    $this->post('/blacklist', ['type' => 'channel', 'value' => 'loud']);

    expect(HistoryEvent::ownedBy($this->alice)->count())->toBe(2)
        ->and(HistoryEvent::ownedBy($this->bob)->count())->toBe(0)
        ->and(Stream::ownedBy($this->alice)->where('user_login', 'loud')->exists())->toBeTrue()
        ->and(Stream::ownedBy($this->bob)->where('user_login', 'loud')->exists())->toBeFalse();
});

// ── Sync ─────────────────────────────────────────────────────────────

test('a sync asks Twitch once per category and gives each account its own filtered streams', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    Category::factory()->for($this->alice, 'owner')->create(['twitch_id' => '777']);
    Category::factory()->for($this->bob, 'owner')->create(['twitch_id' => '777']);
    setAccountSetting('global_min_viewers', '1000', $this->bob);
    BlacklistRule::factory()->for($this->alice, 'owner')->create(['type' => 'channel', 'value' => 'blocked_by_alice']);

    $twitch->shouldReceive('getAllStreamsForCategory')->once()->with('777')->andReturn([
        liveStream(['user_login' => 'small', 'viewer_count' => 50]),
        liveStream(['user_login' => 'big', 'viewer_count' => 5000]),
        liveStream(['user_login' => 'blocked_by_alice', 'viewer_count' => 9000]),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->never();
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $result = app(SyncService::class)->sync();

    expect(Stream::ownedBy($this->alice)->pluck('user_login')->sort()->values()->all())->toBe(['big', 'small'])
        ->and(Stream::ownedBy($this->bob)->pluck('user_login')->sort()->values()->all())->toBe(['big', 'blocked_by_alice'])
        ->and($result->newStreams)->toBe(4);

    // Each account gets its own history.
    expect(HistoryEvent::ownedBy($this->alice)->where('type', 'stream_online')->count())->toBe(2)
        ->and(HistoryEvent::ownedBy($this->bob)->where('type', 'stream_online')->count())->toBe(2)
        ->and(HistoryEvent::ownedBy($this->alice)->where('type', 'sync_completed')->count())->toBe(1)
        ->and(HistoryEvent::ownedBy($this->bob)->where('type', 'sync_completed')->count())->toBe(1);
});

test('a sync fetches the tracked channels of all accounts in one request', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);
    $tracker->shouldReceive('getAvgViewers')->andReturn(null);

    TrackedChannel::factory()->for($this->alice, 'owner')->create(['user_login' => 'shared']);
    TrackedChannel::factory()->for($this->alice, 'owner')->create(['user_login' => 'only_alice']);
    TrackedChannel::factory()->for($this->bob, 'owner')->create(['user_login' => 'shared']);

    $twitch->shouldReceive('getStreamsByUsers')->once()
        ->withArgs(fn (array $logins) => collect($logins)->sort()->values()->all() === ['only_alice', 'shared'])
        ->andReturn([
            liveStream(['user_login' => 'shared']),
            liveStream(['user_login' => 'only_alice']),
        ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    expect(Stream::ownedBy($this->alice)->pluck('user_login')->sort()->values()->all())->toBe(['only_alice', 'shared'])
        ->and(Stream::ownedBy($this->bob)->pluck('user_login')->all())->toBe(['shared']);
});

test('a disabled account is not synced', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $this->bob->forceFill(['disabled_at' => now()])->save();
    Category::factory()->for($this->alice, 'owner')->create(['twitch_id' => '777']);
    Category::factory()->for($this->bob, 'owner')->create(['twitch_id' => '888']);

    $twitch->shouldReceive('getAllStreamsForCategory')->once()->with('777')->andReturn([liveStream()]);
    $twitch->shouldReceive('getStreamsByUsers')->never();
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    expect(Stream::ownedBy($this->alice)->count())->toBe(1)
        ->and(Stream::ownedBy($this->bob)->count())->toBe(0);
});

test('"sync now" refreshes only the signed-in account', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    Category::factory()->for($this->alice, 'owner')->create(['twitch_id' => '777']);
    Category::factory()->for($this->bob, 'owner')->create(['twitch_id' => '888']);

    $twitch->shouldReceive('isConfigured')->andReturn(true);
    $twitch->shouldReceive('getAllStreamsForCategory')->once()->with('888')->andReturn([liveStream()]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $this->actingAs($this->bob)->post('/sync')->assertRedirect();

    expect(Stream::ownedBy($this->bob)->count())->toBe(1)
        ->and(Stream::ownedBy($this->alice)->count())->toBe(0)
        ->and(Setting::get('last_sync_at'))->toBeNull();
});

// ── Alerts and where they are delivered ──────────────────────────────

test('an alert fires only for its own account and is delivered to that account\'s destinations', function () {
    Http::fake();
    Mail::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    Category::factory()->for($this->alice, 'owner')->create(['twitch_id' => '777']);
    Category::factory()->for($this->bob, 'owner')->create(['twitch_id' => '777']);

    // Alice wants Discord, Bob wants e-mail; only Alice has an alert rule that matches.
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/1/alice'], $this->alice);
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/2/bob'], $this->bob);
    destination('email', ['to' => 'bob@example.com'], [], $this->bob);
    AlertRule::factory()->for($this->alice, 'owner')->withDiscord()->create(['name' => 'Alice rule']);
    AlertRule::factory()->for($this->bob, 'owner')->withDiscord()->withEmail()->create(['name' => 'Bob rule', 'min_viewers' => 100000]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([liveStream(['user_login' => 'someone'])]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/webhooks/1/alice');
    Mail::assertNothingSent();

    expect(HistoryEvent::ownedBy($this->alice)->where('type', 'alert_triggered')->count())->toBe(1)
        ->and(HistoryEvent::ownedBy($this->bob)->where('type', 'alert_triggered')->count())->toBe(0);
});

test('two accounts with the same alert are each notified at their own destination', function () {
    Http::fake();
    Mail::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    foreach ([$this->alice, $this->bob] as $account) {
        Category::factory()->for($account, 'owner')->create(['twitch_id' => '777']);
        AlertRule::factory()->for($account, 'owner')->withDiscord()->withEmail()->create();
        destination('discord', [], ['webhook_url' => "https://discord.com/api/webhooks/{$account->id}/token"], $account);
        destination('email', ['to' => $account->email], [], $account);
    }
    Setting::set('smtp_host', 'smtp.example.com');
    NotificationDestination::ownedBy($this->bob)->where('type', 'email')->update(['enabled' => false]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([liveStream()]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === "https://discord.com/api/webhooks/{$this->alice->id}/token");
    Http::assertSent(fn ($request) => $request->url() === "https://discord.com/api/webhooks/{$this->bob->id}/token");

    // Bob switched e-mail off for himself; that does not switch it off for Alice.
    Mail::assertSentCount(1);
    Mail::assertSent(StreamAlertMail::class, fn ($mail) => $mail->hasTo('alice@example.com'));
});

// ── Settings ─────────────────────────────────────────────────────────

test('every account saves its own filters and alert destinations', function () {
    $this->actingAs($this->alice)->put('/settings', ['global_min_viewers' => 50, 'theme' => 'dark'])->assertSessionHasNoErrors();
    $this->post('/settings/destinations', [
        'name' => 'Alice mail', 'type' => 'email', 'enabled' => true, 'config' => ['to' => 'alice-alerts@example.com'],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->bob)->put('/settings', ['global_min_viewers' => 900])->assertSessionHasNoErrors();
    $this->post('/settings/destinations', [
        'name' => 'Bob hook', 'type' => 'webhook', 'enabled' => true,
        'config' => ['method' => 'POST', 'auth' => 'none'], 'secrets' => ['url' => 'https://hooks.example.com/bob'],
    ])->assertSessionHasNoErrors();

    expect(UserSetting::get($this->alice, 'global_min_viewers'))->toBe('50')
        ->and(UserSetting::get($this->bob, 'global_min_viewers'))->toBe('900')
        ->and(NotificationDestination::ownedBy($this->alice)->pluck('name')->all())->toBe(['Alice mail'])
        ->and(NotificationDestination::ownedBy($this->bob)->pluck('name')->all())->toBe(['Bob hook']);

    // Nothing of this lands in the installation's settings.
    expect(Setting::get('global_min_viewers'))->toBeNull();

    $this->actingAs($this->alice)->get('/settings')->assertInertia(fn ($page) => $page->has('destinations', 1)->where('destinations.0.name', 'Alice mail'));
    $this->actingAs($this->alice)->get('/')->assertInertia(fn ($page) => $page->where('appSettings.theme', 'dark'));
    $this->actingAs($this->bob)->get('/')->assertInertia(fn ($page) => $page->where('appSettings.theme', 'system'));
});

test('an account\'s destinations are stored encrypted and only ever shown masked, and only to that account', function () {
    destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://hooks.example.com/secret-path-alice'], $this->alice);
    destination('telegram', ['chat_id' => '42'], ['bot_token' => '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], $this->alice);

    $raw = DB::table('notification_destinations')->pluck('secrets')->implode(' ');
    expect($raw)->not->toContain('secret-path-alice')->not->toContain('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');

    $response = $this->actingAs($this->alice)->get('/settings');
    $response->assertInertia(fn ($page) => $page
        ->where('destinations.0.secrets_set.url', true)
        ->where('destinations.1.secrets_set.bot_token', true)
        ->missing('destinations.0.secrets')
        ->missing('destinations.1.secrets'));
    expect($response->getContent())->not->toContain('secret-path-alice')->not->toContain('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');

    $response = $this->actingAs($this->bob)->get('/settings');
    $response->assertInertia(fn ($page) => $page->has('destinations', 0));
    expect($response->getContent())->not->toContain('hooks.example.com');
});

test('a regular account can neither read nor change the installation settings', function () {
    Setting::set('twitch_client_id', 'installationclientid');
    Setting::set('twitch_client_secret', 'installation-secret-value');
    Setting::set('smtp_host', 'smtp.internal.example');
    Setting::set('sync_frequency_minutes', '5');

    $response = $this->actingAs($this->bob)->get('/settings');
    $response->assertInertia(fn ($page) => $page
        ->missing('settings.twitch_client_id')
        ->missing('settings.twitch_client_secret_set')
        ->missing('settings.smtp_host')
        ->missing('settings.sync_frequency_minutes')
        ->where('activeCategoriesCount', 0)
        ->where('emailAvailable', true));
    expect($response->getContent())->not->toContain('installationclientid')->not->toContain('smtp.internal.example');

    $this->put('/settings', [
        'twitch_client_id' => 'hijacked',
        'twitch_client_secret' => 'hijacked-secret',
        'smtp_host' => 'evil.example.com',
        'sync_frequency_minutes' => 1440,
        'auto_sync_enabled' => false,
        'clear_secrets' => ['twitch_client_secret', 'smtp_password'],
        'global_min_viewers' => 7,
    ])->assertSessionHasNoErrors();

    expect(Setting::get('twitch_client_id'))->toBe('installationclientid')
        ->and(Setting::get('twitch_client_secret'))->toBe('installation-secret-value')
        ->and(Setting::get('smtp_host'))->toBe('smtp.internal.example')
        ->and(Setting::get('sync_frequency_minutes'))->toBe('5')
        ->and(Setting::get('auto_sync_enabled'))->toBeNull()
        // their own setting in the same request is still saved
        ->and(UserSetting::get($this->bob, 'global_min_viewers'))->toBe('7');
});

test('an administrator saves installation settings and their own settings in one request', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put('/settings', [
        'sync_frequency_minutes' => 10,
        'smtp_host' => 'smtp.example.com',
        'global_min_viewers' => 25,
    ])->assertSessionHasNoErrors();

    expect(Setting::get('sync_frequency_minutes'))->toBe('10')
        ->and(Setting::get('smtp_host'))->toBe('smtp.example.com')
        ->and(UserSetting::get($admin, 'global_min_viewers'))->toBe('25')
        ->and(Setting::get('global_min_viewers'))->toBeNull();

    $this->get('/settings')->assertInertia(fn ($page) => $page
        ->where('settings.smtp_host', 'smtp.example.com')
        ->where('settings.global_min_viewers', '25'));
});

test('a regular account\'s webhook is still held to the outbound URL rules', function () {
    $this->actingAs($this->bob)->post('/settings/destinations', [
        'name' => 'Internal', 'type' => 'webhook', 'enabled' => true,
        'config' => ['method' => 'POST', 'auth' => 'none'], 'secrets' => ['url' => 'http://127.0.0.1:8080/internal'],
    ])->assertSessionHasErrors('secrets.url');
    $this->post('/settings/destinations', [
        'name' => 'Fake Discord', 'type' => 'discord', 'enabled' => true, 'secrets' => ['webhook_url' => 'https://example.com/not-discord'],
    ])->assertSessionHasErrors('secrets.webhook_url');

    expect(NotificationDestination::ownedBy($this->bob)->count())->toBe(0);
});

test('testing a destination uses the signed-in account\'s own destination', function () {
    Http::fake();
    $alices = destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://hooks.example.com/alice'], $this->alice);

    $this->actingAs($this->bob)->postJson("/settings/destinations/{$alices->id}/test")->assertNotFound();
    Http::assertNothingSent();

    $this->actingAs($this->alice)->postJson("/settings/destinations/{$alices->id}/test")->assertJson(['success' => true]);
    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.com/alice');
});

// ── Lifecycle ────────────────────────────────────────────────────────

test('deleting an account deletes everything it owns and nothing else', function () {
    foreach ([$this->alice, $this->bob] as $account) {
        $category = Category::factory()->for($account, 'owner')->create();
        Stream::factory()->for($account, 'owner')->forCategory($category)->create();
        TrackedChannel::factory()->for($account, 'owner')->create();
        BlacklistRule::factory()->for($account, 'owner')->create();
        HistoryEvent::factory()->for($account, 'owner')->create();
        TagFilter::createFor($account, ['tag' => 'retro']);
        $rule = AlertRule::factory()->for($account, 'owner')->create();
        StreamAlertTracking::create(['alert_rule_id' => $rule->id, 'stream_twitch_id' => 'x', 'streamer_login' => 'x']);
        setAccountSetting('theme', 'dark', $account);
        destination('webhook', ['method' => 'POST', 'auth' => 'none'], ['url' => 'https://hooks.example.com/'.$account->id], $account);
    }

    $this->actingAs(User::factory()->admin()->create())->delete("/users/{$this->bob->id}")->assertRedirect();

    foreach ([Category::class, Stream::class, TrackedChannel::class, BlacklistRule::class, HistoryEvent::class, TagFilter::class, AlertRule::class, NotificationDestination::class] as $model) {
        expect($model::ownedBy($this->bob->id)->count())->toBe(0, $model)
            ->and($model::ownedBy($this->alice)->count())->toBe(1, $model);
    }
    expect(UserSetting::where('user_id', $this->bob->id)->count())->toBe(0)
        ->and(UserSetting::where('user_id', $this->alice->id)->count())->toBe(1)
        ->and(StreamAlertTracking::count())->toBe(1);
});

test('account data cannot be created without an owner', function () {
    expect(fn () => Category::create(['twitch_id' => '1', 'name' => 'Orphan']))->toThrow(LogicException::class);
    expect(fn () => HistoryEvent::create(['type' => 'stream_online']))->toThrow(LogicException::class);
});

// ── Backup (administrators) ──────────────────────────────────────────

test('a backup contains only the exporting administrator\'s own data and imports into their own account', function () {
    $admin = User::factory()->admin()->create();
    Category::factory()->for($admin, 'owner')->create(['twitch_id' => '111', 'name' => 'Admin Game']);
    Category::factory()->for($this->alice, 'owner')->create(['twitch_id' => '222', 'name' => 'Alice Game']);
    AlertRule::factory()->for($this->alice, 'owner')->create(['name' => 'Alice Alert']);
    destination('email', ['to' => 'admin-alerts@example.com'], [], $admin);
    destination('email', ['to' => 'alice-alerts@example.com'], [], $this->alice);

    $export = $this->actingAs($admin)->getJson('/settings/export')->assertOk()->json();

    expect(collect($export['categories'])->pluck('name')->all())->toBe(['Admin Game'])
        ->and($export['alerts'])->toBe([])
        ->and($export['destinations'])->toBe([['name' => 'Email', 'type' => 'email', 'enabled' => true, 'config' => ['to' => 'admin-alerts@example.com', 'subject_prefix' => '']]])
        ->and(json_encode($export))->not->toContain('Alice');

    // Importing the same file as another administrator creates their own copy.
    $other = User::factory()->admin()->create();
    $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($export));
    $this->actingAs($other)->post('/settings/import', ['file' => $file])->assertSessionMissing('error');

    expect(Category::ownedBy($other)->pluck('name')->all())->toBe(['Admin Game'])
        ->and(NotificationDestination::ownedBy($other)->sole()->recipients())->toBe(['admin-alerts@example.com'])
        ->and(Category::ownedBy($admin)->count())->toBe(1)
        ->and(Category::ownedBy($this->alice)->pluck('name')->all())->toBe(['Alice Game']);
});

test('a rejected webhook is reported on the field and says how to allow a self-hosted server', function () {
    // The suite resolves every host to a public address; make this one private.
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (string $host) => ['192.168.1.20']));
    $payload = [
        'name' => 'Home hook', 'type' => 'webhook', 'enabled' => true,
        'config' => ['method' => 'POST', 'auth' => 'none'], 'secrets' => ['url' => 'https://hooks.home.example.net/in'],
    ];

    $this->actingAs($this->bob)->post('/settings/destinations', $payload)
        ->assertSessionHasErrors(['secrets.url' => 'The URL is not allowed: The URL resolves to a private, loopback or reserved address. To use a server on your own network, ask an administrator to allow its host name.']);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/settings/destinations', $payload)->assertSessionHasErrors('secrets.url');
    expect(session('errors')->first('secrets.url'))->toContain('WEBHOOK_ALLOWED_HOSTS');

    // Once the host is allowed, the same URL is accepted.
    config(['security.webhook.allowed_hosts' => ['hooks.home.example.net']]);
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(fn (string $host) => ['192.168.1.20']));
    $this->post('/settings/destinations', $payload)->assertSessionHasNoErrors();
    expect(NotificationDestination::ownedBy($admin)->sole()->secret('url'))->toBe('https://hooks.home.example.net/in');
});
