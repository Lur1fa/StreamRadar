<?php

use App\Models\AlertRule;
use App\Models\Category;
use App\Models\Stream;
use App\Models\TrackedChannel;
use App\Services\SyncService;
use App\Services\TwitchApiService;
use App\Services\TwitchTrackerService;

function twitchStreamWithoutAvatar(string $id, string $login): array
{
    return [
        'id' => $id, 'user_id' => "u{$id}", 'user_login' => $login, 'user_name' => $login,
        'game_id' => '1', 'game_name' => 'Game', 'title' => 'Title', 'viewer_count' => 10,
        'language' => 'en', 'thumbnail_url' => 'https://example.com/t.jpg', 'tags' => [],
    ];
}

test('a full sync looks up missing avatars once, not after every category', function () {
    $user = regularUser();
    $first = Category::factory()->for($user, 'owner')->create();
    $second = Category::factory()->for($user, 'owner')->create();

    $twitch = $this->mock(TwitchApiService::class);
    $this->mock(TwitchTrackerService::class);
    $twitch->shouldReceive('getAllStreamsForCategory')->with($first->twitch_id)->andReturn([twitchStreamWithoutAvatar('1', 'one')]);
    $twitch->shouldReceive('getAllStreamsForCategory')->with($second->twitch_id)->andReturn([twitchStreamWithoutAvatar('2', 'two')]);
    $twitch->shouldReceive('getUsersByIds')->once()->andReturn([
        ['id' => 'u1', 'profile_image_url' => 'https://static-cdn.jtvnw.net/one.png'],
        ['id' => 'u2', 'profile_image_url' => 'https://static-cdn.jtvnw.net/two.png'],
    ]);

    app(SyncService::class)->syncAccount($user);

    expect(Stream::ownedBy($user)->whereNull('profile_image_url')->count())->toBe(0);
});

test('tracked channels skip average-viewer lookups unless an alert rule needs them', function () {
    $user = regularUser();
    TrackedChannel::factory()->for($user, 'owner')->create(['user_login' => 'fav']);

    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([twitchStreamWithoutAvatar('9', 'fav')]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);
    $tracker->shouldNotReceive('getAvgViewersBulk');
    $tracker->shouldNotReceive('getAvgViewers');

    app(SyncService::class)->syncTrackedChannels($user);

    expect(Stream::ownedBy($user)->value('avg_viewers'))->toBeNull();
});

test('tracked channels get average viewers in one bulk lookup when an alert rule needs them', function () {
    $user = regularUser();
    TrackedChannel::factory()->for($user, 'owner')->create(['user_login' => 'fav']);
    AlertRule::factory()->for($user, 'owner')->create(['min_avg_viewers' => 50]);

    $twitch = $this->mock(TwitchApiService::class);
    $tracker = $this->mock(TwitchTrackerService::class);
    $twitch->shouldReceive('getStreamsByUsers')->andReturn([twitchStreamWithoutAvatar('9', 'fav')]);
    $twitch->shouldReceive('getUsersByIds')->andReturn([]);
    $tracker->shouldReceive('getAvgViewersBulk')->once()->with(['fav'])->andReturn(['fav' => 120]);

    app(SyncService::class)->syncTrackedChannels($user);

    expect(Stream::ownedBy($user)->value('avg_viewers'))->toBe(120);
});
