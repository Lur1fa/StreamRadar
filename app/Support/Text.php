<?php

namespace App\Support;

/**
 * Small text helpers shared by validation, notifications and sync.
 */
final class Text
{
    /**
     * ASCII control characters (C0 and DEL). Never allowed in an HTTP header,
     * a URL or a one-line name. UTF-8 multi-byte sequences never contain these
     * bytes, so no /u flag is needed (and invalid UTF-8 does not break it).
     */
    public const CONTROL_CHARS = '/[\x00-\x1F\x7F]/';

    /** The same class as a validation rule: no control characters at all. */
    public const PRINTABLE_RULE = 'regex:/^[^\x00-\x1F\x7F]*$/';

    public static function hasControlChars(string $value): bool
    {
        return preg_match(self::CONTROL_CHARS, $value) === 1;
    }

    /**
     * Replace every run of control characters with one space and trim.
     */
    public static function stripControlChars(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    /**
     * Whether $haystack contains at least one of $needles, ignoring ASCII case.
     *
     * Same semantics as the loops it replaces (AlertRule::matchesStream,
     * SyncService::passesFilters / isBlacklisted): strtolower(), and an empty
     * needle matches.
     *
     * @param  iterable<string>  $needles
     */
    public static function containsAny(string $haystack, iterable $needles): bool
    {
        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
