<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\TwitchApiService;
use Throwable;

/**
 * Made-up alerts for testing a destination. Nothing is saved; avatars are
 * fetched from Twitch when the API is set up, so the message looks real.
 */
class SampleAlerts
{
    public function __construct(private TwitchApiService $twitch) {}

    /**
     * @return array<array{rule: AlertRule, stream: Stream}>
     */
    public function make(int $count = 1): array
    {
        $samples = collect(self::STREAMS)->shuffle()->take(max(1, $count))->values();
        $avatars = $this->avatars($samples->pluck('user_login')->all());

        return $samples->map(function (array $s) use ($avatars) {
            $stream = new Stream([
                'twitch_id' => 'test-'.$s['user_login'],
                'user_login' => $s['user_login'],
                'user_name' => $s['user_name'],
                'title' => $s['title'],
                'viewer_count' => $s['viewers'],
                'language' => $s['language'],
                'game_name' => $s['game'],
                'tags' => [$s['game']],
                'is_mature' => false,
                'profile_image_url' => $avatars[$s['user_login']] ?? null,
                'started_at' => now()->subMinutes(random_int(5, 120)),
            ]);

            return ['rule' => new AlertRule(['name' => $s['rule'].' (test)']), 'stream' => $stream];
        })->all();
    }

    /**
     * The fixed sample the template editor previews with. No Twitch lookup.
     *
     * @return array{rule: AlertRule, stream: Stream}
     */
    public static function preview(): array
    {
        $s = self::STREAMS[2];

        return [
            'rule' => new AlertRule(['name' => $s['rule']]),
            'stream' => new Stream([
                'twitch_id' => 'preview-'.$s['user_login'],
                'user_login' => $s['user_login'],
                'user_name' => $s['user_name'],
                'title' => $s['title'],
                'viewer_count' => $s['viewers'],
                'language' => $s['language'],
                'game_name' => $s['game'],
                'tags' => ['English', 'Esports'],
                'is_mature' => false,
                'started_at' => now()->subMinutes(42)->startOfMinute(),
            ]),
        ];
    }

    /**
     * @param  string[]  $logins
     * @return array<string, string|null>
     */
    private function avatars(array $logins): array
    {
        try {
            if (! $this->twitch->isConfigured()) {
                return [];
            }

            return collect($this->twitch->getUsers($logins))
                ->mapWithKeys(fn ($u) => [$u['login'] => $u['profile_image_url'] ?? null])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private const STREAMS = [
        [
            'user_login' => 'shroud', 'user_name' => 'shroud',
            'title' => 'VALORANT Ranked Grind — Road to Radiant',
            'game' => 'VALORANT', 'viewers' => 42_831, 'language' => 'en',
            'rule' => 'FPS Streams',
        ],
        [
            'user_login' => 'pokimane', 'user_name' => 'pokimane',
            'title' => 'cozy morning stream — chatting & games',
            'game' => 'Just Chatting', 'viewers' => 28_445, 'language' => 'en',
            'rule' => 'Favorite Streamers',
        ],
        [
            'user_login' => 'caedrel', 'user_name' => 'Caedrel',
            'title' => 'LEC Watch Party — Playoffs Day 2',
            'game' => 'League of Legends', 'viewers' => 55_210, 'language' => 'en',
            'rule' => 'LoL Esports',
        ],
        [
            'user_login' => 'jynxzi', 'user_name' => 'Jynxzi',
            'title' => 'RAINBOW SIX SIEGE — Going for Diamond!!',
            'game' => 'Tom Clancy\'s Rainbow Six Siege', 'viewers' => 31_672, 'language' => 'en',
            'rule' => 'FPS Streams',
        ],
        [
            'user_login' => 'ibai', 'user_name' => 'ibai',
            'title' => 'LA VELADA DEL AÑO 4 — PREPARACION',
            'game' => 'Just Chatting', 'viewers' => 112_300, 'language' => 'es',
            'rule' => 'Big Spanish Streams',
        ],
    ];
}
