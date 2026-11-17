<?php

namespace App\Support;

use App\Models\NotificationDestination;
use App\Models\Setting;
use App\Models\UserSetting;
use Throwable;

/**
 * Central scrubbing of secrets from anything that leaves the process:
 * log lines, error messages returned to the browser, and DevTools payloads.
 */
final class Redactor
{
    public const REDACTED = '[REDACTED]';

    /** Key names (case-insensitive substring match) whose values are secrets. */
    private const KEY_PATTERN = '/(secret|passw(or)?d|passphrase|token|api[_\-]?key|private[_\-]?key|credential|authorization|cookie|webhook|client[_\-]?secret|code[_\-]?verifier|signature)/i';

    /**
     * Scrub a free-text message (exception text, log line).
     */
    public static function message(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Telegram bot tokens embedded in API URLs.
        $text = preg_replace('#/bot\d+:[A-Za-z0-9_\-]+#', '/bot'.self::REDACTED, $text) ?? $text;

        // Any URL: keep scheme + host, drop userinfo, path, query and fragment.
        $text = preg_replace_callback('#https?://[^\s\'"<>]+#i', function (array $m) {
            $parts = parse_url($m[0]);
            if (! is_array($parts) || empty($parts['host'])) {
                return self::REDACTED;
            }

            return ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/…';
        }, $text) ?? $text;

        // Bearer / Basic credentials in header-like text.
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._\-+\/=]+/i', '$1 '.self::REDACTED, $text) ?? $text;

        // Finally, literal occurrences of every configured secret value.
        foreach (self::knownSecrets() as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * Recursively redact an array by key pattern and by known secret values.
     */
    public static function array(array $data): array
    {
        $secrets = self::knownSecrets();

        return self::walk($data, $secrets);
    }

    public static function isSensitiveKey(string|int $key): bool
    {
        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    private static function walk(array $data, array $secrets): array
    {
        foreach ($data as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::walk($value, $secrets);

                continue;
            }

            if (is_string($value)) {
                $data[$key] = self::scrubValue($value, $secrets);
            }
        }

        return $data;
    }

    private static function scrubValue(string $value, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                $value = str_replace($secret, self::REDACTED, $value);
            }
        }

        // Telegram tokens anywhere in a string value.
        return preg_replace('#\b\d{6,}:[A-Za-z0-9_\-]{30,}\b#', self::REDACTED, $value) ?? $value;
    }

    /**
     * Current values of every secret setting (and OIDC client secret), so
     * that a secret is scrubbed wherever it appears regardless of key name.
     *
     * @return string[]
     */
    private static function knownSecrets(): array
    {
        $values = [];

        try {
            foreach (Setting::SECRET_KEYS as $key) {
                $v = Setting::get($key);
                if (is_string($v) && strlen($v) >= 6) {
                    $values[] = $v;
                }
            }

            // Credentials every account stores for its own alerts.
            foreach ([...UserSetting::secretValues(), ...NotificationDestination::secretValues()] as $v) {
                if (strlen($v) >= 6) {
                    $values[] = $v;
                }
            }
        } catch (Throwable) {
            // No database (e.g. during early bootstrap) — pattern scrubbing still applies.
        }

        $oidcSecret = config('oidc.client_secret');
        if (is_string($oidcSecret) && strlen($oidcSecret) >= 6) {
            $values[] = $oidcSecret;
        }

        // Longest first so partial overlaps are handled deterministically.
        usort($values, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $values;
    }
}
