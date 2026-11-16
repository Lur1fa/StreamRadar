<?php

namespace App\Services;

use App\DTOs\SyncResult;
use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\TrackedChannel;
use App\Support\Redactor;
use Illuminate\Support\Facades\Log;

class SyncService
{
    private ?bool $anyAlertNeedsAvg = null;

    public function __construct(
        private TwitchApiService $twitch,
        private AlertService $alerts,
        private TwitchTrackerService $tracker,
    ) {}

    public function getAlertService(): AlertService
    {
        return $this->alerts;
    }

    public function sync(): SyncResult
    {
        $start = microtime(true);
        $totals = ['new' => 0, 'updated' => 0, 'alerts' => 0];

        $categories = Category::where('is_active', true)->get();
        $allFetchedStreamIds = [];
        $allApiIds = [];
        $allTriggered = [];

        // Sync categories
        foreach ($categories as $category) {
            $result = $this->syncCategory($category);
            $totals['new'] += $result['new'];
            $totals['updated'] += $result['updated'];
            $totals['alerts'] += $result['alerts'];
            array_push($allFetchedStreamIds, ...$result['stream_ids']);
            array_push($allApiIds, ...$result['all_api_ids']);
            array_push($allTriggered, ...$result['triggered_alerts']);
        }

        // Sync tracked channels
        $channelResult = $this->syncTrackedChannels();
        $totals['new'] += $channelResult['new'];
        $totals['updated'] += $channelResult['updated'];
        $totals['alerts'] += $channelResult['alerts'];
        array_push($allFetchedStreamIds, ...$channelResult['stream_ids']);
        array_push($allApiIds, ...$channelResult['all_api_ids']);
        array_push($allTriggered, ...$channelResult['triggered_alerts']);

        // Remove streams no longer in our filtered set
        $missingQuery = Stream::with('category');
        if (! empty($allFetchedStreamIds)) {
            $missingQuery->whereNotIn('twitch_id', $allFetchedStreamIds);
        }
        $missingStreams = $missingQuery->get();

        $endedCount = 0;
        $gracePeriodMinutes = max(3 * (int) Setting::get('sync_frequency_minutes', 5), 3);

        foreach ($missingStreams as $stream) {
            $stillLiveOnTwitch = in_array($stream->twitch_id, $allApiIds);

            if ($stillLiveOnTwitch) {
                // Stream is live but filtered out (e.g. below min_avg_viewers)
                // Remove immediately, no offline event
                $stream->delete();
                $endedCount++;
                continue;
            }

            // Stream not in API — might be actually offline or API hiccup
            // Apply grace period
            if (! $stream->missing_since) {
                $stream->update(['missing_since' => now()]);
                continue;
            }

            if ($stream->missing_since->diffInMinutes(now()) < $gracePeriodMinutes) {
                continue;
            }

            // Grace period expired — actually offline
            HistoryEvent::create([
                'type' => 'stream_offline',
                'stream_twitch_id' => $stream->twitch_id,
                'streamer_login' => $stream->user_login,
                'streamer_name' => $stream->user_name,
                'category_name' => $stream->category?->name,
                'title' => $stream->title,
                'viewer_count' => $stream->viewer_count,
                'profile_image_url' => $stream->profile_image_url,
            ]);

            $stream->delete();
            $endedCount++;
        }

        $this->fetchMissingAvatars();

        // Send all alert notifications in one batch. Notification delivery is
        // best-effort: whatever happens inside, the sync state below is always
        // recorded so the scheduler never gets stuck on a failing provider.
        try {
            $this->alerts->sendNotifications($allTriggered);
        } catch (\Throwable $e) {
            Log::error('Alert notification dispatch failed', [
                'exception' => $e::class,
                'error' => Redactor::message($e->getMessage()),
            ]);
        } finally {
            Setting::set('last_sync_at', now()->toIso8601String());

            $duration = round(microtime(true) - $start, 2);

            HistoryEvent::create([
                'type' => 'sync_completed',
                'metadata' => [
                    'new' => $totals['new'],
                    'updated' => $totals['updated'],
                    'ended' => $endedCount,
                    'alerts' => $totals['alerts'],
                    'duration' => $duration,
                ],
            ]);
        }

        return new SyncResult(
            newStreams: $totals['new'],
            updatedStreams: $totals['updated'],
            endedStreams: $endedCount,
            alertsTriggered: $totals['alerts'],
            durationSeconds: $duration,
        );
    }

    public function syncCategory(Category $category, bool $silentAlerts = false): array
    {
        $newStreams = 0;
        $updatedStreams = 0;
        $alertsTriggered = 0;
        $fetchedIds = [];
        $allApiIds = [];
        $allTriggered = [];

        $blacklist = $this->loadBlacklist();

        try {
            $twitchStreams = $this->twitch->getAllStreamsForCategory($category->twitch_id);
        } catch (\Exception $e) {
            Log::error("Failed to fetch streams for category {$category->name}: ".Redactor::message($e->getMessage()));

            return ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];
        }

        $filters = $category->effectiveFilters();

        // Prefetch avg_viewers if category filter or any alert rule requires it
        $avgViewersMap = [];
        $this->anyAlertNeedsAvg ??= AlertRule::where('is_active', true)->whereNotNull('min_avg_viewers')->exists();
        if (! empty($filters['min_avg_viewers']) || $this->anyAlertNeedsAvg) {
            $logins = array_map(fn ($s) => strtolower($s['user_login'] ?? ''), $twitchStreams);
            $avgViewersMap = $this->tracker->getAvgViewersBulk(array_unique(array_filter($logins)));
        }

        foreach ($twitchStreams as $twitchStream) {
            $allApiIds[] = $twitchStream['id'];

            if (! $this->passesFilters($twitchStream, $filters, $avgViewersMap)) {
                continue;
            }

            if ($this->isOfflineThumbnail($twitchStream['thumbnail_url'] ?? null)) {
                continue;
            }

            if ($this->isBlacklisted($twitchStream, $blacklist)) {
                continue;
            }

            $fetchedIds[] = $twitchStream['id'];
            $existing = Stream::where('twitch_id', $twitchStream['id'])->first();
            $isNew = ! $existing;
            $oldStream = $existing ? clone $existing : null;

            $stream = Stream::updateOrCreate(
                ['twitch_id' => $twitchStream['id']],
                [
                    'user_id' => $twitchStream['user_id'] ?? '',
                    'user_login' => $twitchStream['user_login'] ?? '',
                    'user_name' => $twitchStream['user_name'] ?? '',
                    'category_id' => $category->id,
                    'game_name' => $twitchStream['game_name'] ?? $category->name,
                    'game_box_art_url' => ! empty($twitchStream['game_id']) ? "https://static-cdn.jtvnw.net/ttv-boxart/{$twitchStream['game_id']}_IGDB-{width}x{height}.jpg" : $category->box_art_url,
                    'title' => $twitchStream['title'] ?? '',
                    'viewer_count' => $twitchStream['viewer_count'] ?? 0,
                    'avg_viewers' => $avgViewersMap[strtolower($twitchStream['user_login'] ?? '')] ?? null,
                    'language' => $twitchStream['language'] ?? null,
                    'thumbnail_url' => $twitchStream['thumbnail_url'] ?? null,
                    'started_at' => $twitchStream['started_at'] ?? null,
                    'tags' => $twitchStream['tags'] ?? null,
                    'is_mature' => $twitchStream['is_mature'] ?? false,
                    'synced_at' => now(),
                    'missing_since' => null,
                ],
            );

            $stream->setRelation('category', $category);

            $triggered = $this->alerts->checkAlerts($stream, $oldStream, $silentAlerts);
            $alertsTriggered += count($triggered);
            array_push($allTriggered, ...$triggered);

            if ($isNew) {
                $newStreams++;

                HistoryEvent::create([
                    'type' => 'stream_online',
                    'stream_twitch_id' => $stream->twitch_id,
                    'streamer_login' => $stream->user_login,
                    'streamer_name' => $stream->user_name,
                    'category_name' => $category->name,
                    'title' => $stream->title,
                    'viewer_count' => $stream->viewer_count,
                    'profile_image_url' => $stream->profile_image_url,
                ]);
            } else {
                $updatedStreams++;
            }
        }

        $this->fetchMissingAvatars();

        return ['new' => $newStreams, 'updated' => $updatedStreams, 'alerts' => $alertsTriggered, 'stream_ids' => $fetchedIds, 'all_api_ids' => $allApiIds, 'triggered_alerts' => $allTriggered];
    }

    public function syncTrackedChannels(bool $silentAlerts = false): array
    {
        $channels = TrackedChannel::where('is_active', true)->get();
        if ($channels->isEmpty()) {
            return ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];
        }

        $logins = $channels->pluck('user_login')->toArray();
        $blacklist = $this->loadBlacklist();
        $newStreams = 0;
        $updatedStreams = 0;
        $alertsTriggered = 0;
        $fetchedIds = [];
        $allApiIds = [];
        $allTriggered = [];

        try {
            $twitchStreams = $this->twitch->getStreamsByUsers($logins);
        } catch (\Exception $e) {
            Log::error('Failed to fetch tracked channel streams: '.Redactor::message($e->getMessage()));

            return ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];
        }

        // Map game_id to existing categories (don't auto-create)
        $gameIds = collect($twitchStreams)->pluck('game_id')->unique()->filter()->toArray();
        $categoryMap = Category::whereIn('twitch_id', $gameIds)->pluck('id', 'twitch_id')->toArray();

        foreach ($twitchStreams as $twitchStream) {
            $allApiIds[] = $twitchStream['id'];

            if ($this->isOfflineThumbnail($twitchStream['thumbnail_url'] ?? null)) {
                continue;
            }

            if ($this->isBlacklisted($twitchStream, $blacklist)) {
                continue;
            }

            $fetchedIds[] = $twitchStream['id'];
            $existing = Stream::where('twitch_id', $twitchStream['id'])->first();
            $isNew = ! $existing;
            $oldStream = $existing ? clone $existing : null;

            $categoryId = $categoryMap[$twitchStream['game_id'] ?? ''] ?? null;

            $stream = Stream::updateOrCreate(
                ['twitch_id' => $twitchStream['id']],
                [
                    'user_id' => $twitchStream['user_id'] ?? '',
                    'user_login' => $twitchStream['user_login'] ?? '',
                    'user_name' => $twitchStream['user_name'] ?? '',
                    'category_id' => $categoryId,
                    'game_name' => $twitchStream['game_name'] ?? null,
                    'game_box_art_url' => ! empty($twitchStream['game_id']) ? "https://static-cdn.jtvnw.net/ttv-boxart/{$twitchStream['game_id']}_IGDB-{width}x{height}.jpg" : null,
                    'title' => $twitchStream['title'] ?? '',
                    'viewer_count' => $twitchStream['viewer_count'] ?? 0,
                    'avg_viewers' => $this->tracker->getAvgViewers($twitchStream['user_login'] ?? ''),
                    'language' => $twitchStream['language'] ?? null,
                    'thumbnail_url' => $twitchStream['thumbnail_url'] ?? null,
                    'started_at' => $twitchStream['started_at'] ?? null,
                    'tags' => $twitchStream['tags'] ?? null,
                    'is_mature' => $twitchStream['is_mature'] ?? false,
                    'synced_at' => now(),
                    'missing_since' => null,
                ],
            );

            $stream->load('category');

            $triggered = $this->alerts->checkAlerts($stream, $oldStream, $silentAlerts);
            $alertsTriggered += count($triggered);
            array_push($allTriggered, ...$triggered);

            if ($isNew) {
                $newStreams++;
                HistoryEvent::create([
                    'type' => 'stream_online',
                    'stream_twitch_id' => $stream->twitch_id,
                    'streamer_login' => $stream->user_login,
                    'streamer_name' => $stream->user_name,
                    'category_name' => $twitchStream['game_name'] ?? null,
                    'title' => $stream->title,
                    'viewer_count' => $stream->viewer_count,
                    'profile_image_url' => $stream->profile_image_url,
                ]);
            } else {
                $updatedStreams++;
            }
        }

        $this->fetchMissingAvatars();

        return ['new' => $newStreams, 'updated' => $updatedStreams, 'alerts' => $alertsTriggered, 'stream_ids' => $fetchedIds, 'all_api_ids' => $allApiIds, 'triggered_alerts' => $allTriggered];
    }

    private function loadBlacklist(): array
    {
        return [
            'channels' => BlacklistRule::channels()->pluck('value')->map(fn ($v) => strtolower($v))->toArray(),
            'keywords' => BlacklistRule::keywords()->pluck('value')->map(fn ($v) => strtolower($v))->toArray(),
            'tags' => BlacklistRule::tags()->pluck('value')->map(fn ($v) => strtolower($v))->toArray(),
        ];
    }

    private function isBlacklisted(array $stream, array $blacklist): bool
    {
        $login = strtolower($stream['user_login'] ?? '');
        if (in_array($login, $blacklist['channels'])) {
            return true;
        }

        $title = strtolower($stream['title'] ?? '');
        foreach ($blacklist['keywords'] as $keyword) {
            if (str_contains($title, $keyword)) {
                return true;
            }
        }

        $streamTags = array_map('strtolower', $stream['tags'] ?? []);
        foreach ($blacklist['tags'] as $tag) {
            if (in_array($tag, $streamTags)) {
                return true;
            }
        }

        return false;
    }

    private function isOfflineThumbnail(?string $url): bool
    {
        return $url && str_contains($url, 'ttv-static/404_preview');
    }

    private function passesFilters(array $stream, array $filters, array $avgViewersMap = []): bool
    {
        if (! empty($filters['min_viewers']) && ($stream['viewer_count'] ?? 0) < $filters['min_viewers']) {
            return false;
        }

        if (! empty($filters['min_avg_viewers'])) {
            $login = strtolower($stream['user_login'] ?? '');
            $avg = $avgViewersMap[$login] ?? ($stream['viewer_count'] ?? 0);
            if ($avg < $filters['min_avg_viewers']) {
                return false;
            }
        }

        if (! empty($filters['languages'])) {
            $streamLang = strtolower($stream['language'] ?? '');
            $acceptedLangs = array_map('strtolower', $filters['languages']);
            if (! in_array($streamLang, $acceptedLangs)) {
                return false;
            }
        }

        if (! empty($filters['keywords'])) {
            $titleLower = strtolower($stream['title'] ?? '');
            $matched = false;
            foreach ($filters['keywords'] as $keyword) {
                if (str_contains($titleLower, strtolower($keyword))) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function fetchMissingAvatars(): void
    {
        $streams = Stream::whereNull('profile_image_url')
            ->select('user_id', 'user_login')
            ->distinct()
            ->limit(100)
            ->get();

        if ($streams->isEmpty()) {
            return;
        }

        try {
            $userIds = $streams->pluck('user_id')->unique()->toArray();
            $users = $this->twitch->getUsersByIds($userIds);

            $avatarMap = collect($users)->keyBy('id')->map(fn ($u) => $u['profile_image_url'] ?? null);

            foreach ($avatarMap as $userId => $avatarUrl) {
                if ($avatarUrl) {
                    Stream::where('user_id', $userId)->update(['profile_image_url' => $avatarUrl]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch avatars: '.Redactor::message($e->getMessage()));
        }
    }
}
