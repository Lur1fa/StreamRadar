<?php

namespace App\Services;

use App\Models\ChannelStats;
use App\Support\Brand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwitchTrackerService
{
    private const CACHE_HOURS = 24;

    /**
     * Get avg_viewers for a channel. Returns cached value if fresh, otherwise fetches from TwitchTracker.
     */
    public function getAvgViewers(string $userLogin): ?int
    {
        $login = strtolower($userLogin);

        return $this->getAvgViewersBulk([$login])[$login] ?? null;
    }

    /**
     * Bulk-fetch avg_viewers for multiple logins. Only fetches stale/missing entries.
     * Returns map of login => avg_viewers.
     */
    public function getAvgViewersBulk(array $userLogins): array
    {
        $logins = array_map('strtolower', $userLogins);
        $existing = ChannelStats::whereIn('user_login', $logins)->get()->keyBy('user_login');
        $result = [];

        foreach ($logins as $login) {
            $stats = $existing->get($login);
            $result[$login] = self::isFresh($stats) ? $stats->avg_viewers : $this->fetchAndCache($login, $stats);
        }

        return $result;
    }

    private static function isFresh(?ChannelStats $stats): bool
    {
        return $stats?->fetched_at !== null && $stats->fetched_at->diffInHours(now()) < self::CACHE_HOURS;
    }

    private function fetchAndCache(string $login, ?ChannelStats $stats): ?int
    {
        try {
            $response = Http::timeout(5)
                ->withUserAgent(Brand::USER_AGENT)
                ->get('https://twitchtracker.com/api/channels/summary/'.rawurlencode($login));

            if (! $response->successful()) {
                return $stats?->avg_viewers;
            }

            $avgViewers = $response->json('avg_viewers');
            $avgViewers = $avgViewers !== null ? (int) $avgViewers : null;

            ChannelStats::updateOrCreate(
                ['user_login' => $login],
                ['avg_viewers' => $avgViewers, 'fetched_at' => now()],
            );

            return $avgViewers;
        } catch (\Exception $e) {
            Log::debug("TwitchTracker fetch failed for {$login}: {$e->getMessage()}");

            return $stats?->avg_viewers;
        }
    }
}
