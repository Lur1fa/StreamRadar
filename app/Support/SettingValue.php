<?php

namespace App\Support;

/**
 * Conversions between request/import values and what the settings tables
 * store (strings, with '1' / '0' for booleans and JSON for lists).
 */
final class SettingValue
{
    /**
     * A value as stored: null stays null, booleans become '1' / '0',
     * everything else its string form.
     */
    public static function encode(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * A boolean-ish input ('1', 'true', 'on', true, …) stored as '1' / '0'.
     */
    public static function flag(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    /**
     * A stored JSON list (global_languages, global_keywords). Anything that
     * is not a JSON array, including an empty or broken value, is [].
     *
     * @return array<int, mixed>
     */
    public static function decodeList(mixed $stored): array
    {
        if (! is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
