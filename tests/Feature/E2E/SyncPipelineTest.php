<?php

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use App\Services\TwitchTrackerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function twitchStream(array $overrides = []): array
{
    static $counter = 0;
    $counter++;

    return array_merge([
        'id' => (string) (100000 + $counter),
        'user_id' => (string) (50000 + $counter),
        'user_login' => 'streamer'.$counter,
        'user_name' => 'Streamer'.$counter,
        'game_id' => '12345',
        'game_name' => 'Test Game',
        'title' => 'Playing some game',
        'viewer_count' => 500,
        'language' => 'en',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'started_at' => now()->subHour()->toIso8601String(),
        'tags' => ['English'],
        'is_mature' => false,
    ], $overrides);
}

// ═══════════════════════════════════════════════════════════════════
// GLOBAL FILTERS
// ═══════════════════════════════════════════════════════════════════

test('E2E: global min_viewers filters out streams below threshold', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    setAccountSetting('global_min_viewers', '100');

    $category = Category::factory()->create(['use_global_filters' => true, 'filter_source' => 'global']);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['viewer_count' => 50, 'user_login' => 'small']),
        twitchStream(['viewer_count' => 200, 'user_login' => 'big']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->sync();

    expect($result->newStreams)->toBe(1);
    $this->assertDatabaseHas('streams', ['user_login' => 'big']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'small']);
});

test('E2E: global language filter only keeps matching streams', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    setAccountSetting('global_languages', json_encode(['pl']));

    $category = Category::factory()->create(['use_global_filters' => true, 'filter_source' => 'global']);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['language' => 'en', 'user_login' => 'english']),
        twitchStream(['language' => 'pl', 'user_login' => 'polish']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseHas('streams', ['user_login' => 'polish']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'english']);
});

// ═══════════════════════════════════════════════════════════════════
// TAG FILTERS
// ═══════════════════════════════════════════════════════════════════

test('E2E: tag filter min_viewers applies to tagged categories', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    TagFilter::createFor(testOwner(), ['tag' => 'retro', 'min_viewers' => 50]);

    $category = Category::factory()->create([
        'tags' => ['retro'],
        'filter_source' => 'tag:retro',
        'use_global_filters' => false,
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['viewer_count' => 10, 'user_login' => 'tiny']),
        twitchStream(['viewer_count' => 100, 'user_login' => 'decent']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseHas('streams', ['user_login' => 'decent']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'tiny']);
});

test('E2E: tag filter falls back to global when tag has no filter defined', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    setAccountSetting('global_min_viewers', '200');
    // No TagFilter for 'indie' — should use global

    $category = Category::factory()->create([
        'tags' => ['indie'],
        'filter_source' => 'tag:indie',
        'use_global_filters' => false,
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['viewer_count' => 150, 'user_login' => 'below_global']),
        twitchStream(['viewer_count' => 300, 'user_login' => 'above_global']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseHas('streams', ['user_login' => 'above_global']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'below_global']);
});

// ═══════════════════════════════════════════════════════════════════
// CUSTOM FILTERS
// ═══════════════════════════════════════════════════════════════════

test('E2E: custom category filters override global', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    setAccountSetting('global_min_viewers', '500');

    $category = Category::factory()->create([
        'filter_source' => 'custom',
        'use_global_filters' => false,
        'min_viewers' => 10,
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['viewer_count' => 50, 'user_login' => 'passes_custom']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Would fail global (50 < 500) but passes custom (50 >= 10)
    $this->assertDatabaseHas('streams', ['user_login' => 'passes_custom']);
});

// ═══════════════════════════════════════════════════════════════════
// AVG VIEWERS (TWITCHTRACKER)
// ═══════════════════════════════════════════════════════════════════

test('E2E: min_avg_viewers filters using TwitchTracker data', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create([
        'filter_source' => 'custom',
        'use_global_filters' => false,
        'min_avg_viewers' => 100,
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'big_avg', 'viewer_count' => 50]),
        twitchStream(['user_login' => 'small_avg', 'viewer_count' => 50]),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $tracker->shouldReceive('getAvgViewersBulk')->andReturn([
        'big_avg' => 500,
        'small_avg' => 30,
    ]);

    app(SyncService::class)->sync();

    $this->assertDatabaseHas('streams', ['user_login' => 'big_avg']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'small_avg']);
});

test('E2E: min_avg_viewers falls back to current viewers when TwitchTracker has no data', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create([
        'filter_source' => 'custom',
        'use_global_filters' => false,
        'min_avg_viewers' => 100,
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'unknown_big', 'viewer_count' => 200]),
        twitchStream(['user_login' => 'unknown_small', 'viewer_count' => 30]),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    // TwitchTracker returns nothing for these channels
    $tracker->shouldReceive('getAvgViewersBulk')->andReturn([]);

    app(SyncService::class)->sync();

    // Falls back to viewer_count: 200 >= 100, 30 < 100
    $this->assertDatabaseHas('streams', ['user_login' => 'unknown_big']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'unknown_small']);
});

test('E2E: TwitchTracker not called when min_avg_viewers is zero', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create(['filter_source' => 'global', 'use_global_filters' => true]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'anyone']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $tracker->shouldNotReceive('getAvgViewersBulk');

    app(SyncService::class)->sync();

    $this->assertDatabaseHas('streams', ['user_login' => 'anyone']);
});

// ═══════════════════════════════════════════════════════════════════
// BLACKLIST
// ═══════════════════════════════════════════════════════════════════

test('E2E: blacklisted channel is excluded from sync', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    BlacklistRule::factory()->channel('banned_user')->create();
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'banned_user']),
        twitchStream(['user_login' => 'good_user']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseMissing('streams', ['user_login' => 'banned_user']);
    $this->assertDatabaseHas('streams', ['user_login' => 'good_user']);
});

test('E2E: blacklisted keyword in title excludes stream', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    BlacklistRule::factory()->keyword('gambling')->create();
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'gambler', 'title' => 'Gambling streams all day']),
        twitchStream(['user_login' => 'gamer', 'title' => 'Playing ranked']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseMissing('streams', ['user_login' => 'gambler']);
    $this->assertDatabaseHas('streams', ['user_login' => 'gamer']);
});

test('E2E: blacklisted tag excludes stream', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    BlacklistRule::factory()->tag('gambling')->create();
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'tagged', 'tags' => ['Gambling', 'English']]),
        twitchStream(['user_login' => 'clean', 'tags' => ['English']]),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseMissing('streams', ['user_login' => 'tagged']);
    $this->assertDatabaseHas('streams', ['user_login' => 'clean']);
});

// ═══════════════════════════════════════════════════════════════════
// ALERTS + NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════

test('E2E: full pipeline — sync triggers alert and sends Discord notification', function () {
    Http::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);

    $category = Category::factory()->create();
    AlertRule::factory()->withDiscord()->create(['min_viewers' => 100]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['viewer_count' => 500, 'user_login' => 'popular']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Stream created
    $this->assertDatabaseHas('streams', ['user_login' => 'popular']);
    // Alert triggered
    $this->assertDatabaseHas('stream_alert_tracking', ['streamer_login' => 'popular']);
    // History events
    $this->assertDatabaseHas('history_events', ['type' => 'alert_triggered']);
    $this->assertDatabaseHas('history_events', ['type' => 'stream_online']);
    // Discord called
    Http::assertSentCount(1);
});

test('E2E: full pipeline — sync triggers alert and sends Email notification', function () {
    Mail::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    destination('email', ['to' => 'test@example.com']);

    $category = Category::factory()->create();
    AlertRule::factory()->withEmail()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'emailme']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    Mail::assertSentCount(1);
});

test('E2E: alert with category_tags only triggers for matching tagged category', function () {
    Http::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);

    $retro = Category::factory()->create(['tags' => ['retro']]);
    $fps = Category::factory()->create(['tags' => ['fps']]);

    // Alert only for 'retro' tagged categories
    AlertRule::factory()->withDiscord()->create(['category_tags' => ['retro']]);

    $twitch->shouldReceive('getAllStreamsForCategory')
        ->with($retro->twitch_id)->andReturn([twitchStream(['user_login' => 'retro_player'])]);
    $twitch->shouldReceive('getAllStreamsForCategory')
        ->with($fps->twitch_id)->andReturn([twitchStream(['user_login' => 'fps_player'])]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Both streams created
    expect(Stream::count())->toBe(2);
    // Only retro triggered alert
    expect(StreamAlertTracking::count())->toBe(1);
    $this->assertDatabaseHas('stream_alert_tracking', ['streamer_login' => 'retro_player']);
});

test('E2E: alert with notify_on_stream_start=false does not trigger on new stream', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create();
    AlertRule::factory()->create([
        'notify_on_stream_start' => false,
        'notify_on_category_change' => true,
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['user_login' => 'newstreamer']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseHas('streams', ['user_login' => 'newstreamer']);
    expect(StreamAlertTracking::count())->toBe(0);
});

test('E2E: alert with notify_on_category_change triggers when game changes', function () {
    Http::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);

    $category = Category::factory()->create();
    AlertRule::factory()->withDiscord()->create(['notify_on_category_change' => true]);

    // Existing stream with old game
    Stream::factory()->forCategory($category)->create([
        'twitch_id' => '999',
        'user_login' => 'switcher',
        'game_name' => 'Old Game',
    ]);

    // Sync returns same stream with new game
    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['id' => '999', 'user_login' => 'switcher', 'game_name' => 'New Game']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Alert triggered for category change
    $this->assertDatabaseHas('stream_alert_tracking', ['streamer_login' => 'switcher']);
    Http::assertSentCount(1);
});

test('E2E: alert does NOT trigger on category change when notify_on_category_change is false', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create();
    AlertRule::factory()->create(['notify_on_category_change' => false]);

    Stream::factory()->forCategory($category)->create([
        'twitch_id' => '999',
        'user_login' => 'switcher',
        'game_name' => 'Old Game',
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['id' => '999', 'user_login' => 'switcher', 'game_name' => 'New Game']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    expect(StreamAlertTracking::count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════
// TRACKED CHANNELS
// ═══════════════════════════════════════════════════════════════════

test('E2E: tracked channel appears regardless of category filters', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);
    $tracker->shouldReceive('getAvgViewers')->andReturn(null);

    setAccountSetting('global_min_viewers', '1000');

    TrackedChannel::factory()->create(['user_login' => 'myfav']);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->with(['myfav'])->andReturn([
        twitchStream(['user_login' => 'myfav', 'viewer_count' => 5]),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Despite global min_viewers=1000, tracked channel with 5 viewers still appears
    $this->assertDatabaseHas('streams', ['user_login' => 'myfav']);
});

// ═══════════════════════════════════════════════════════════════════
// STREAM OFFLINE — only for actually offline streams
// ═══════════════════════════════════════════════════════════════════

test('E2E: missing stream gets grace period before removal', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create();
    Stream::factory()->forCategory($category)->create([
        'twitch_id' => '888',
        'user_login' => 'flickering',
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // First sync: stream marked as missing, NOT deleted
    $this->assertDatabaseHas('streams', ['twitch_id' => '888']);
    expect(Stream::where('twitch_id', '888')->first()->missing_since)->not->toBeNull();
    $this->assertDatabaseMissing('history_events', ['type' => 'stream_offline']);
});

test('E2E: stream returns during grace period — missing_since reset', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create();
    Stream::factory()->forCategory($category)->create([
        'twitch_id' => '888',
        'user_login' => 'flickering',
        'missing_since' => now()->subMinute(),
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['id' => '888', 'user_login' => 'flickering']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Stream is back — missing_since cleared
    expect(Stream::where('twitch_id', '888')->first()->missing_since)->toBeNull();
    $this->assertDatabaseMissing('history_events', ['type' => 'stream_offline']);
});

test('E2E: stream removed after grace period expires', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $category = Category::factory()->create();
    Stream::factory()->forCategory($category)->create([
        'twitch_id' => '777',
        'user_login' => 'went_offline',
        'missing_since' => now()->subMinutes(20),  // well past grace period
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    $this->assertDatabaseMissing('streams', ['twitch_id' => '777']);
    $this->assertDatabaseHas('history_events', ['type' => 'stream_offline', 'stream_twitch_id' => '777']);
});

test('E2E: filtered stream removed silently without offline event after grace', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    setAccountSetting('global_min_viewers', '100');

    $category = Category::factory()->create(['use_global_filters' => true, 'filter_source' => 'global']);
    Stream::factory()->forCategory($category)->create([
        'twitch_id' => '888',
        'user_login' => 'below_threshold',
        'viewer_count' => 50,
        'missing_since' => now()->subMinutes(20),
    ]);

    // Stream still live on Twitch (in allApiIds) but below filter
    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        twitchStream(['id' => '888', 'user_login' => 'below_threshold', 'viewer_count' => 50]),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    app(SyncService::class)->sync();

    // Removed but NO offline event (still live, just filtered)
    $this->assertDatabaseMissing('streams', ['twitch_id' => '888']);
    $this->assertDatabaseMissing('history_events', ['type' => 'stream_offline', 'stream_twitch_id' => '888']);
});

// ═══════════════════════════════════════════════════════════════════
// COMBINED SCENARIO
// ═══════════════════════════════════════════════════════════════════

test('E2E: full scenario — multiple categories, filters, blacklist, alerts, notifications', function () {
    Http::fake();
    Mail::fake();
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);

    // Setup
    setAccountSetting('global_min_viewers', '50');
    destination('discord', [], ['webhook_url' => 'https://discord.com/api/webhooks/test']);
    destination('email', ['to' => 'admin@test.com']);

    TagFilter::createFor(testOwner(), ['tag' => 'retro', 'min_viewers' => 5]);

    $mainstream = Category::factory()->create([
        'filter_source' => 'global',
        'use_global_filters' => true,
    ]);

    $retro = Category::factory()->create([
        'tags' => ['retro'],
        'filter_source' => 'tag:retro',
        'use_global_filters' => false,
    ]);

    BlacklistRule::factory()->channel('spammer')->create();

    // Alert 1: Discord for retro tagged categories
    AlertRule::factory()->withDiscord()->create(['category_tags' => ['retro']]);
    // Alert 2: Email for all
    AlertRule::factory()->withEmail()->create();

    // Mainstream category streams
    $twitch->shouldReceive('getAllStreamsForCategory')
        ->with($mainstream->twitch_id)->andReturn([
            twitchStream(['user_login' => 'big_streamer', 'viewer_count' => 200]),
            twitchStream(['user_login' => 'small_streamer', 'viewer_count' => 10]),  // below global 50
            twitchStream(['user_login' => 'spammer', 'viewer_count' => 300]),  // blacklisted
        ]);

    // Retro category streams
    $twitch->shouldReceive('getAllStreamsForCategory')
        ->with($retro->twitch_id)->andReturn([
            twitchStream(['user_login' => 'retro_fan', 'viewer_count' => 8]),  // passes tag filter (>=5)
        ]);

    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $result = app(SyncService::class)->sync();

    // Streams: big_streamer (passes global) + retro_fan (passes tag)
    expect(Stream::count())->toBe(2);
    $this->assertDatabaseHas('streams', ['user_login' => 'big_streamer']);
    $this->assertDatabaseHas('streams', ['user_login' => 'retro_fan']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'small_streamer']);
    $this->assertDatabaseMissing('streams', ['user_login' => 'spammer']);

    // Alerts: retro alert triggers for retro_fan, email alert triggers for both
    expect($result->alertsTriggered)->toBe(3);

    // Discord (1 call for retro_fan) + no discord for big_streamer (alert 1 is retro-only)
    Http::assertSentCount(1);
    // Email sent (both streams match alert 2)
    Mail::assertSentCount(1);
});
