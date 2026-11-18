<?php

use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\TrackedChannel;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use App\Services\TwitchTrackerService;

function makeTwitchStream(array $overrides = []): array
{
    return array_merge([
        'id' => (string) fake()->unique()->numberBetween(100000, 9999999),
        'user_id' => '12345',
        'user_login' => 'teststreamer',
        'user_name' => 'TestStreamer',
        'game_id' => '12345',
        'game_name' => 'Test Game',
        'title' => 'Test stream title',
        'viewer_count' => 500,
        'language' => 'en',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'started_at' => now()->subHour()->toIso8601String(),
        'tags' => ['English'],
        'is_mature' => false,
    ], $overrides);
}

// syncCategory()

test('syncCategory creates new streams from Twitch data', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->with($category->twitch_id)->andReturn([makeTwitchStream()]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->syncCategory($category);

    expect($result['new'])->toBe(1)
        ->and(Stream::count())->toBe(1);
});

test('syncCategory updates existing streams', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();
    $stream = Stream::factory()->forCategory($category)->create(['twitch_id' => '999']);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['id' => '999', 'title' => 'Updated title']),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->syncCategory($category);

    expect($result['updated'])->toBe(1)
        ->and($stream->fresh()->title)->toBe('Updated title');
});

test('syncCategory skips streams below min_viewers filter', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->withLocalFilters(minViewers: 1000)->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['viewer_count' => 50]),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->syncCategory($category);

    expect($result['new'])->toBe(0)
        ->and(Stream::count())->toBe(0);
});

test('syncCategory skips streams with wrong language', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->withLocalFilters(0, ['en'])->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['language' => 'pl']),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->syncCategory($category);

    expect(Stream::count())->toBe(0);
});

test('syncCategory skips streams with offline thumbnail', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['thumbnail_url' => 'https://static-cdn.jtvnw.net/ttv-static/404_preview-440x248.jpg']),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->syncCategory($category);

    expect(Stream::count())->toBe(0);
});

test('syncCategory skips blacklisted channels', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();
    BlacklistRule::factory()->channel('badstreamer')->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['user_login' => 'badstreamer']),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->syncCategory($category);

    expect(Stream::count())->toBe(0);
});

test('syncCategory skips blacklisted keywords in title', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();
    BlacklistRule::factory()->keyword('gambling')->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['title' => 'Come watch gambling streams']),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->syncCategory($category);

    expect(Stream::count())->toBe(0);
});

test('syncCategory skips blacklisted tags', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();
    BlacklistRule::factory()->tag('gambling')->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['tags' => ['Gambling', 'English']]),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->syncCategory($category);

    expect(Stream::count())->toBe(0);
});

test('syncCategory creates stream_online HistoryEvent for new streams', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([makeTwitchStream()]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->syncCategory($category);

    $this->assertDatabaseHas('history_events', ['type' => 'stream_online']);
});

test('syncCategory handles Twitch API exception gracefully', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();

    $twitch->shouldReceive('getAllStreamsForCategory')->andThrow(new \Exception('API error'));

    $sync = app(SyncService::class);
    $result = $sync->syncCategory($category);

    expect($result['new'])->toBe(0)
        ->and($result['updated'])->toBe(0);
});

// sync()

test('sync marks missing streams with missing_since on first absence', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();
    Stream::factory()->forCategory($category)->create(['twitch_id' => 'old_stream']);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([
        makeTwitchStream(['id' => 'new_stream']),
    ]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->sync();

    // Grace period: stream still in DB but marked missing
    expect($result->endedStreams)->toBe(0);
    $this->assertDatabaseHas('streams', ['twitch_id' => 'old_stream']);
    expect(Stream::where('twitch_id', 'old_stream')->first()->missing_since)->not->toBeNull();
});

test('sync removes streams after grace period expires', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $category = Category::factory()->create();
    Stream::factory()->forCategory($category)->create([
        'twitch_id' => 'old_stream',
        'missing_since' => now()->subMinutes(20),
    ]);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->sync();

    expect($result->endedStreams)->toBe(1);
    $this->assertDatabaseHas('history_events', ['type' => 'stream_offline', 'stream_twitch_id' => 'old_stream']);
    $this->assertDatabaseMissing('streams', ['twitch_id' => 'old_stream']);
});

test('sync creates sync_completed HistoryEvent', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    Category::factory()->create(); // the event is recorded for every account that tracks something

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->sync();

    $this->assertDatabaseHas('history_events', ['type' => 'sync_completed']);
});

test('sync updates last_sync_at setting', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $sync->sync();

    expect(Setting::get('last_sync_at'))->not->toBeNull();
});

test('sync returns SyncResult DTO', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);

    $twitch->shouldReceive('getAllStreamsForCategory')->andReturn([]);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->sync();

    expect($result)->toBeInstanceOf(\App\DTOs\SyncResult::class)
        ->and($result->durationSeconds)->toBeFloat();
});

// syncTrackedChannels()

test('syncTrackedChannels returns empty result when no active channels', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $sync = app(SyncService::class);

    $result = $sync->syncTrackedChannels(testOwner());

    expect($result['new'])->toBe(0);
});

test('syncTrackedChannels creates streams for tracked channels', function () {
    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);
    $tracker->shouldReceive('getAvgViewers')->andReturn(null);
    TrackedChannel::factory()->create(['user_login' => 'trackeduser']);

    $twitch->shouldReceive('getStreamsByUsers')->with(['trackeduser'])->andReturn([
        makeTwitchStream(['user_login' => 'trackeduser']),
    ]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);

    $sync = app(SyncService::class);
    $result = $sync->syncTrackedChannels(testOwner());

    expect($result['new'])->toBe(1)
        ->and(Stream::count())->toBe(1);
});
