<?php

namespace App\Support;

/**
 * How StreamRadar presents itself to people (e-mails, Discord) and to the
 * servers it contacts.
 */
final class Brand
{
    /** Twitch purple. */
    public const COLOR = '#9147ff';

    public const USER_AGENT = 'StreamRadar/1.0 (+https://github.com/stmn/StreamRadar)';

    /** COLOR as the integer Discord embeds expect. */
    public static function colorInt(): int
    {
        return (int) hexdec(ltrim(self::COLOR, '#'));
    }
}
