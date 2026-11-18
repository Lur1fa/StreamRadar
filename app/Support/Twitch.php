<?php

namespace App\Support;

/**
 * Twitch URLs and image templates in one place.
 *
 * Twitch hands out image URLs (thumbnails, box art) as templates with
 * `{width}` and `{height}` placeholders. Replaces the hand-written
 * str_replace() calls in Stream, Category, AlertService, StreamAlertMail
 * and the box-art URL built twice in SyncService.
 */
final class Twitch
{
    /** Part of the thumbnail URL Twitch serves for a stream that has already ended. */
    public const OFFLINE_THUMBNAIL_MARKER = 'ttv-static/404_preview';

    /** A Twitch login: letters, digits and underscores, at most 25 characters. */
    public const LOGIN_PATTERN = '/^[A-Za-z0-9_]{1,25}$/';

    public static function channelUrl(string $login): string
    {
        return 'https://www.twitch.tv/'.rawurlencode($login);
    }

    /**
     * Fill a Twitch image template. Null when there is no template.
     */
    public static function sizedImage(?string $template, int $width, int $height): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }

        return str_replace(['{width}', '{height}'], [(string) $width, (string) $height], $template);
    }

    /**
     * Box-art template of a game, as Twitch's CDN serves it. Null without a game id.
     */
    public static function boxArtTemplate(?string $gameId): ?string
    {
        if (empty($gameId)) {
            return null;
        }

        return 'https://static-cdn.jtvnw.net/ttv-boxart/'.rawurlencode($gameId).'_IGDB-{width}x{height}.jpg';
    }

    public static function isOfflineThumbnail(?string $url): bool
    {
        return $url !== null && str_contains($url, self::OFFLINE_THUMBNAIL_MARKER);
    }
}
