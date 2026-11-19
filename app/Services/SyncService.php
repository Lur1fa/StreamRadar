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
use App\Support\Text;
use App\Support\Twitch;
use Closure;
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
    /** What syncCategory() / syncTrackedChannels() return when nothing was stored. */
    private const EMPTY_RESULT = ['new' => 0, 'updated' => 0, 'alerts' => 0, 'stream_ids' => [], 'all_api_ids' => [], 'triggered_alerts' => []];

    /** @var array<int, bool> per account: does any active alert need average viewers? */
    private array $alertsNeedAvg = [];

    /** True while a full sync runs and Twitch responses are shared between accounts. */
    private bool $sharing = false;

    /** True while an account is collected; missing avatars are then fetched once at the end. */
    private bool $deferAvatars = false;

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
        $startedAt = now();
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
            Setting::set('last_sync_at', $startedAt->toIso8601String());
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
        $this->deferAvatars = true;

        try {
            $results = Category::ownedBy($account)->where('is_active', true)->get()
                ->map(fn (Category $category) => $this->syncCategory($category))
                ->push($this->syncTrackedChannels($account))
                ->all();
        } finally {
            $this->deferAvatars = false;
        }

        $result = self::merge($results);

        return [
            'account' => $account,
            'new' => $result['new'],
            'updated' => $result['updated'],
            'ended' => $this->endMissingStreams($account, $result['stream_ids'], $result['all_api_ids']),
            'alerts' => $result['alerts'],
            'triggered' => $result['triggered_alerts'],
        ];
    }

    /**
     * Remove the account's streams that are no longer in its filtered set.
     * A stream that is still live but now filtered out goes at once; one
     * Twitch no longer lists gets a grace period (API hiccups) before it is
     * recorded as offline.
     *
     * @param  string[]  $keptIds  streams stored in this run
     * @param  string[]  $liveIds  every stream Twitch returned in this run
     * @return int streams removed
     */
    private function endMissingStreams(User $account, array $keptIds, array $liveIds): int
    {
        $missingQuery = Stream::with('category')->ownedBy($account);
        if ($keptIds !== []) {
            $missingQuery->whereNotIn('twitch_id', $keptIds);
        }

        $live = array_flip($liveIds);
        $gracePeriodMinutes = max(3 * Setting::syncFrequencyMinutes(), 3);
        $ended = 0;

        foreach ($missingQuery->get() as $stream) {
            if (! isset($live[$stream->twitch_id])) {
                if (! $stream->missing_since) {
                    $stream->update(['missing_since' => now()]);

                    continue;
                }

                if ($stream->missing_since->diffInMinutes(now()) < $gracePeriodMinutes) {
                    continue;
                }

                HistoryEvent::createFor($account, ['type' => HistoryEvent::STREAM_OFFLINE, ...HistoryEvent::streamColumns($stream)]);
            }

            $stream->delete();
            $ended++;
        }

        return $ended;
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
                    'type' => HistoryEvent::SYNC_COMPLETED,
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
        $ownerId = (int) $category->owner_id;

        try {
            $twitchStreams = $this->categoryStreams($category->twitch_id);
        } catch (\Exception $e) {
            Log::error("Failed to fetch streams for category {$category->name}: ".Redactor::message($e->getMessage()));

            return self::EMPTY_RESULT;
        }

        $filters = $category->effectiveFilters();
        $avgViewers = $this->avgViewersFor($ownerId, $twitchStreams, filterNeedsIt: ! empty($filters['min_avg_viewers']));

        return $this->storeStreams($ownerId, $twitchStreams, $silentAlerts,
            skip: fn (array $s) => ! $this->passesFilters($s, $filters, $avgViewers),
            columns: fn (array $s) => [
                'category_id' => $category->id,
                'game_name' => $s['game_name'] ?? $category->name,
                'game_box_art_url' => Twitch::boxArtTemplate($s['game_id'] ?? null) ?? $category->box_art_url,
                'avg_viewers' => $avgViewers[strtolower($s['user_login'] ?? '')] ?? null,
            ],
            category: $category,
        );
    }

    public function syncTrackedChannels(User|int $owner, bool $silentAlerts = false): array
    {
        $ownerId = $owner instanceof User ? (int) $owner->id : $owner;
        $logins = TrackedChannel::ownedBy($ownerId)->where('is_active', true)->pluck('user_login')->all();

        if ($logins === []) {
            return self::EMPTY_RESULT;
        }

        try {
            $twitchStreams = $this->channelStreams($logins);
        } catch (\Exception $e) {
            Log::error('Failed to fetch tracked channel streams: '.Redactor::message($e->getMessage()));

            return self::EMPTY_RESULT;
        }

        // Link to the account's existing categories (never created here).
        $gameIds = collect($twitchStreams)->pluck('game_id')->unique()->filter()->all();
        $categoryIds = Category::ownedBy($ownerId)->whereIn('twitch_id', $gameIds)->pluck('id', 'twitch_id')->all();
        $avgViewers = $this->avgViewersFor($ownerId, $twitchStreams);

        return $this->storeStreams($ownerId, $twitchStreams, $silentAlerts,
            skip: fn () => false,
            columns: fn (array $s) => [
                'category_id' => $categoryIds[$s['game_id'] ?? ''] ?? null,
                'game_name' => $s['game_name'] ?? null,
                'game_box_art_url' => Twitch::boxArtTemplate($s['game_id'] ?? null),
                'avg_viewers' => $avgViewers[strtolower($s['user_login'] ?? '')] ?? null,
            ],
        );
    }

    /**
     * Store the given Twitch streams for one account, run its alerts and add
     * new streams to its history. Shared by category and channel tracking.
     *
     * @param  array<int, array<string, mixed>>  $twitchStreams
     * @param  Closure(array): bool  $skip  source-specific filter; true drops the stream
     * @param  Closure(array): array  $columns  source-specific columns (category, game, average viewers)
     * @param  Category|null  $category  the tracked category the streams came from, if any
     */
    private function storeStreams(int $ownerId, array $twitchStreams, bool $silentAlerts, Closure $skip, Closure $columns, ?Category $category = null): array
    {
        $result = self::EMPTY_RESULT;
        $blacklist = $this->loadBlacklist($ownerId);

        foreach ($twitchStreams as $s) {
            $result['all_api_ids'][] = $s['id'];

            if ($skip($s) || Twitch::isOfflineThumbnail($s['thumbnail_url'] ?? null) || $this->isBlacklisted($s, $blacklist)) {
                continue;
            }

            $result['stream_ids'][] = $s['id'];
            $existing = Stream::ownedBy($ownerId)->where('twitch_id', $s['id'])->first();
            $oldStream = $existing ? clone $existing : null;

            $stream = Stream::updateOrCreateFor($ownerId, ['twitch_id' => $s['id']], [
                'user_id' => $s['user_id'] ?? '',
                'user_login' => $s['user_login'] ?? '',
                'user_name' => $s['user_name'] ?? '',
                'title' => $s['title'] ?? '',
                'viewer_count' => $s['viewer_count'] ?? 0,
                'language' => $s['language'] ?? null,
                'thumbnail_url' => $s['thumbnail_url'] ?? null,
                'started_at' => $s['started_at'] ?? null,
                'tags' => $s['tags'] ?? null,
                'is_mature' => $s['is_mature'] ?? false,
                'synced_at' => now(),
                'missing_since' => null,
                ...$columns($s),
            ]);

            $category ? $stream->setRelation('category', $category) : $stream->load('category');

            $triggered = $this->alerts->checkAlerts($stream, $oldStream, $silentAlerts);
            $result['alerts'] += count($triggered);
            array_push($result['triggered_alerts'], ...$triggered);

            if ($oldStream === null) {
                $result['new']++;
                HistoryEvent::createFor($ownerId, [
                    'type' => HistoryEvent::STREAM_ONLINE,
                    ...HistoryEvent::streamColumns($stream, $category?->name ?? ($s['game_name'] ?? null)),
                ]);
            } else {
                $result['updated']++;
            }
        }

        if (! $this->deferAvatars) {
            $this->fetchMissingAvatars();
        }

        return $result;
    }

    /**
     * Results of several syncCategory() / syncTrackedChannels() calls as one.
     *
     * @param  array<int, array>  $results
     */
    private static function merge(array $results): array
    {
        $merged = self::EMPTY_RESULT;
        foreach ($results as $result) {
            foreach (['new', 'updated', 'alerts'] as $count) {
                $merged[$count] += $result[$count];
            }
            foreach (['stream_ids', 'all_api_ids', 'triggered_alerts'] as $list) {
                array_push($merged[$list], ...$result[$list]);
            }
        }

        return $merged;
    }

    /**
     * Average viewers by login, looked up only when the category's filters
     * or one of the account's active alert rules need them.
     *
     * @param  array<int, array<string, mixed>>  $twitchStreams
     * @return array<string, int|null>
     */
    private function avgViewersFor(int $ownerId, array $twitchStreams, bool $filterNeedsIt = false): array
    {
        $this->alertsNeedAvg[$ownerId] ??= AlertRule::ownedBy($ownerId)->where('is_active', true)->whereNotNull('min_avg_viewers')->exists();

        if (! $filterNeedsIt && ! $this->alertsNeedAvg[$ownerId]) {
            return [];
        }

        $logins = array_map(fn ($s) => strtolower($s['user_login'] ?? ''), $twitchStreams);

        return $this->tracker->getAvgViewersBulk(array_unique(array_filter($logins)));
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

    /**
     * The account's blacklist, lower-cased.
     *
     * @return array{channels: string[], keywords: string[], tags: string[]}
     */
    private function loadBlacklist(int $ownerId): array
    {
        $rules = BlacklistRule::ownedBy($ownerId)->get(['type', 'value'])->groupBy('type');
        $values = fn (string $type) => $rules->get($type, collect())->map(fn ($rule) => strtolower($rule->value))->values()->all();

        return [
            'channels' => $values(BlacklistRule::CHANNEL),
            'keywords' => $values(BlacklistRule::KEYWORD),
            'tags' => $values(BlacklistRule::TAG),
        ];
    }

    private function isBlacklisted(array $stream, array $blacklist): bool
    {
        if (in_array(strtolower($stream['user_login'] ?? ''), $blacklist['channels'], true)) {
            return true;
        }

        if (Text::containsAny($stream['title'] ?? '', $blacklist['keywords'])) {
            return true;
        }

        return array_intersect($blacklist['tags'], array_map('strtolower', $stream['tags'] ?? [])) !== [];
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

        if (! empty($filters['languages'])
            && ! in_array(strtolower($stream['language'] ?? ''), array_map('strtolower', $filters['languages']), true)) {
            return false;
        }

        if (! empty($filters['keywords']) && ! Text::containsAny($stream['title'] ?? '', $filters['keywords'])) {
            return false;
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
            $users = $this->twitch->getUsersByIds($streams->pluck('user_id')->unique()->all());

            foreach (collect($users)->keyBy('id')->map(fn ($u) => $u['profile_image_url'] ?? null) as $userId => $avatarUrl) {
                if ($avatarUrl) {
                    Stream::where('user_id', $userId)->update(['profile_image_url' => $avatarUrl]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch avatars: '.Redactor::message($e->getMessage()));
        }
    }
}
