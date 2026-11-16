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
use App\Models\User;
use App\Support\Redactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tracking belongs to accounts, but Twitch is asked only once: during a full
 * sync every category is fetched a single time no matter how many accounts
 * track it, and the live status of all tracked channels is fetched in one
 * go. Each account then gets its own stream rows, filtered by its own
 * filters and blacklist, with its own alerts and history.
 */
class SyncService
{
    /** @var array<int, bool> per account: does any active alert need average viewers? */
    private array $alertsNeedAvg = [];

    /** True while a full sync runs and Twitch responses are shared between accounts. */
    private bool $sharing = false;

    /** @var array<string, array<int, array<string, mixed>>> Twitch category id => live streams */
    private array $sharedCategoryStreams = [];

    /** @var array<int, array<string, mixed>>|null live streams of every tracked channel of every account */
    private ?array $sharedChannelStreams = null;

    public function __construct(
        private TwitchApiService $twitch,
        private AlertService $alerts,
        private TwitchTrackerService $tracker,
    ) {}

    public function getAlertService(): AlertService
    {
        return $this->alerts;
    }

    /**
     * Sync every account that tracks something (what the scheduler runs).
     */
    public function sync(): SyncResult
    {
        $start = microtime(true);
        $runs = [];

        $this->sharing = true;
        $this->sharedCategoryStreams = [];
        $this->sharedChannelStreams = null;

        try {
            $accounts = $this->accountsToSync();
            $this->prefetchTrackedChannels($accounts);

            foreach ($accounts as $account) {
                try {
                    $runs[] = $this->collect($account);
                } catch (Throwable $e) {
                    // One account's data must never stop the others from syncing.
                    Log::error('Sync failed for an account', [
                        'account' => $account->id,
                        'exception' => $e::class,
                        'error' => Redactor::message($e->getMessage()),
                    ]);
                }
            }
        } finally {
            $this->sharing = false;
            $this->sharedCategoryStreams = [];
            $this->sharedChannelStreams = null;
        }

        try {
            return $this->finish($runs, $start);
        } finally {
            Setting::set('last_sync_at', now()->toIso8601String());
        }
    }

    /**
     * Sync one account on demand (the "sync now" button). Always asks Twitch
     * afresh and leaves the schedule of the automatic sync untouched.
     */
    public function syncAccount(User $account): SyncResult
    {
        $start = microtime(true);

        return $this->finish([$this->collect($account)], $start);
    }

    /**
     * Accounts that can sign in and track at least one category or channel.
     *
     * @return Collection<int, User>
     */
    private function accountsToSync(): Collection
    {
        return User::query()
            ->whereNull('disabled_at')
            ->where(function ($q) {
                $q->whereHas('categories', fn ($c) => $c->where('is_active', true))
                    ->orWhereHas('trackedChannels', fn ($c) => $c->where('is_active', true))
                    // Accounts that stopped tracking still need their remaining streams ended.
                    ->orWhereHas('streams');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * One request for the tracked channels of all accounts. When it fails,
     * each account falls back to asking for its own channels.
     *
     * @param  Collection<int, User>  $accounts
     */
    private function prefetchTrackedChannels(Collection $accounts): void
    {
        $logins = TrackedChannel::query()
            ->whereIn('owner_id', $accounts->modelKeys())
            ->where('is_active', true)
            ->pluck('user_login')
            ->map(fn ($login) => strtolower($login))
            ->unique()
            ->values()
            ->all();

        if ($logins === []) {
            $this->sharedChannelStreams = [];

            return;
        }

        try {
            $this->sharedChannelStreams = $this->twitch->getStreamsByUsers($logins);
        } catch (\Exception $e) {
            Log::error('Failed to fetch tracked channel streams: '.Redactor::message($e->getMessage()));
            $this->sharedChannelStreams = null;
        }
    }

    /**
     * Bring one account's streams up to date and work out which of its
     * alerts fired. Sends nothing yet.
     *
     * @return array{account: User, new: int, updated: int, ended: int, alerts: int, triggered: array<int, array{rule: AlertRule, stream: Stream}>}
     */
    private function collect(User $account): array
    {
        $totals = ['new' => 0, 'updated' => 0, 'alerts' => 0];

        $categories = Category::ownedBy($account)->where('is_active', true)->get();
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
        $channelResult = $this->syncTrackedChannels($account);
        $totals['new'] += $channelResult['new'];
        $totals['updated'] += $channelResult['updated'];
        $totals['alerts'] += $channelResult['alerts'];
        array_push($allFetchedStreamIds, ...$channelResult['stream_ids']);
        array_push($allApiIds, ...$channelResult['all_api_ids']);
        array_push($allTriggered, ...$channelResult['triggered_alerts']);

        // Remove streams no longer in this account's filtered set
        $missingQuery = Stream::with('category')->ownedBy($account);
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
            HistoryEvent::createFor($account, [
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

        return [
            'account' => $account,
            'new' => $totals['new'],
            'updated' => $totals['updated'],
            'ended' => $endedCount,
            'alerts' => $totals['alerts'],
            'triggered' => $allTriggered,
        ];
    }

    /**
     * Deliver the alerts of each account and record the sync in its history.
     * Notification delivery is best-effort: whatever happens inside, every
     * account's sync is recorded, so the scheduler never gets stuck on a
     * failing provider.
     *
     * @param  array<int, array{account: User, new: int, updated: int, ended: int, alerts: int, triggered: array}>  $runs
     */
    private function finish(array $runs, float $start): SyncResult
    {
        $this->fetchMissingAvatars();

        $duration = round(microtime(true) - $start, 2);
        $totals = ['new' => 0, 'updated' => 0, 'ended' => 0, 'alerts' => 0];

        foreach ($runs as $run) {
            try {
                $this->alerts->sendNotifications($run['triggered']);
            } catch (Throwable $e) {
                Log::error('Alert notification dispatch failed', [
                    'exception' => $e::class,
                    'error' => Redactor::message($e->getMessage()),
                ]);
            } finally {
                $duration = round(microtime(true) - $start, 2);

                HistoryEvent::createFor($run['account'], [
                    'type' => 'sync_completed',
                    'metadata' => [
                        'new' => $run['new'],
                        'updated' => $run['updated'],
                        'ended' => $run['ended'],
                        'alerts' => $run['alerts'],
                        'duration' => $duration,
                    ],
                ]);
            }

            foreach ($totals as $key => $_) {
                $totals[$key] += $run[$key];
            }
        }

        return new SyncResult(
            newStreams: $totals['new'],
            updatedStreams: $totals['updated'],
            endedStreams: $totals['ended'],
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

        $ownerId = (int) $category->owner_id;
        $blacklist = $this->loadBlacklist($ownerId);

        try {
            $twitchStreams = $this->categoryStreams($category->twitch_id);
        } catch (\Exception $e) {
            Log::error("Failed to fetch streams for category {$category->name}: ".Redactor::message($e->getMessage()));

            return ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];
        }

        $filters = $category->effectiveFilters();

        // Prefetch avg_viewers if category filter or any alert rule requires it
        $avgViewersMap = [];
        $this->alertsNeedAvg[$ownerId] ??= AlertRule::ownedBy($ownerId)->where('is_active', true)->whereNotNull('min_avg_viewers')->exists();
        if (! empty($filters['min_avg_viewers']) || $this->alertsNeedAvg[$ownerId]) {
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
            $existing = Stream::ownedBy($ownerId)->where('twitch_id', $twitchStream['id'])->first();
            $isNew = ! $existing;
            $oldStream = $existing ? clone $existing : null;

            $stream = Stream::updateOrCreateFor(
                $ownerId,
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

                HistoryEvent::createFor($ownerId, [
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

    public function syncTrackedChannels(User|int $owner, bool $silentAlerts = false): array
    {
        $ownerId = $owner instanceof User ? (int) $owner->id : $owner;
        $channels = TrackedChannel::ownedBy($ownerId)->where('is_active', true)->get();
        if ($channels->isEmpty()) {
            return ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];
        }

        $logins = $channels->pluck('user_login')->toArray();
        $blacklist = $this->loadBlacklist($ownerId);
        $newStreams = 0;
        $updatedStreams = 0;
        $alertsTriggered = 0;
        $fetchedIds = [];
        $allApiIds = [];
        $allTriggered = [];

        try {
            $twitchStreams = $this->channelStreams($logins);
        } catch (\Exception $e) {
            Log::error('Failed to fetch tracked channel streams: '.Redactor::message($e->getMessage()));

            return ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];
        }

        // Map game_id to existing categories (don't auto-create)
        $gameIds = collect($twitchStreams)->pluck('game_id')->unique()->filter()->toArray();
        $categoryMap = Category::ownedBy($ownerId)->whereIn('twitch_id', $gameIds)->pluck('id', 'twitch_id')->toArray();

        foreach ($twitchStreams as $twitchStream) {
            $allApiIds[] = $twitchStream['id'];

            if ($this->isOfflineThumbnail($twitchStream['thumbnail_url'] ?? null)) {
                continue;
            }

            if ($this->isBlacklisted($twitchStream, $blacklist)) {
                continue;
            }

            $fetchedIds[] = $twitchStream['id'];
            $existing = Stream::ownedBy($ownerId)->where('twitch_id', $twitchStream['id'])->first();
            $isNew = ! $existing;
            $oldStream = $existing ? clone $existing : null;

            $categoryId = $categoryMap[$twitchStream['game_id'] ?? ''] ?? null;

            $stream = Stream::updateOrCreateFor(
                $ownerId,
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
                HistoryEvent::createFor($ownerId, [
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

    /**
     * Live streams of a Twitch category. During a full sync the answer is
     * reused for every account that tracks the same category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryStreams(string $twitchId): array
    {
        if (! $this->sharing) {
            return $this->twitch->getAllStreamsForCategory($twitchId);
        }

        return $this->sharedCategoryStreams[$twitchId] ??= $this->twitch->getAllStreamsForCategory($twitchId);
    }

    /**
     * Live streams of the given channels, from the shared answer of a full
     * sync when there is one.
     *
     * @param  string[]  $logins
     * @return array<int, array<string, mixed>>
     */
    private function channelStreams(array $logins): array
    {
        if (! $this->sharing || $this->sharedChannelStreams === null) {
            return $this->twitch->getStreamsByUsers($logins);
        }

        $wanted = array_flip(array_map('strtolower', $logins));

        return array_values(array_filter(
            $this->sharedChannelStreams,
            fn ($stream) => isset($wanted[strtolower($stream['user_login'] ?? '')]),
        ));
    }

    private function loadBlacklist(int $ownerId): array
    {
        return [
            'channels' => BlacklistRule::ownedBy($ownerId)->channels()->pluck('value')->map(fn ($v) => strtolower($v))->toArray(),
            'keywords' => BlacklistRule::ownedBy($ownerId)->keywords()->pluck('value')->map(fn ($v) => strtolower($v))->toArray(),
            'tags' => BlacklistRule::ownedBy($ownerId)->tags()->pluck('value')->map(fn ($v) => strtolower($v))->toArray(),
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
