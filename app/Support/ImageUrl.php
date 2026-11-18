<?php

namespace App\Support;

/**
 * Only https images hosted by Twitch's CDN are ever embedded in e-mails,
 * Discord embeds or ntfy attachments. Anything else (including javascript:,
 * data: or attacker-chosen hosts) is dropped.
 */
final class ImageUrl
{
    public static function safe(?string $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F"\'<>]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        foreach ((array) config('security.image_host_suffixes') as $suffix) {
            $suffix = strtolower($suffix);
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $url;
            }
        }

        return null;
    }
}
