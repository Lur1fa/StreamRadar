<?php

namespace App\Services;

/**
 * One Twitch user by login, in the shape the controllers store
 * (tracked channels, blacklist entries, alert rules for one streamer).
 */
class TwitchUserLookup
{
    public function __construct(private TwitchApiService $twitch) {}

    /**
     * Null when Twitch has no such user. Throws when the API fails.
     *
     * @return array{id: string, login: string, display_name: string, profile_image_url: ?string}|null
     */
    public function find(string $login): ?array
    {
        $user = $this->twitch->getUsers([$login])[0] ?? null;

        if ($user === null) {
            return null;
        }

        return [
            'id' => (string) $user['id'],
            'login' => strtolower($user['login'] ?? $login),
            'display_name' => $user['display_name'] ?? $login,
            'profile_image_url' => $user['profile_image_url'] ?? null,
        ];
    }

    /**
     * Like find(), but also null when Twitch is not configured or does not
     * answer: for callers that carry on with what the user typed.
     *
     * @return array{id: string, login: string, display_name: string, profile_image_url: ?string}|null
     */
    public function tryFind(string $login): ?array
    {
        if (! $this->twitch->isConfigured()) {
            return null;
        }

        return rescue(fn () => $this->find($login), null, report: false);
    }
}
