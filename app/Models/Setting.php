<?php

namespace App\Models;

use App\Support\Secrets;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Key/value settings of the installation (SERVER_KEYS, administrators only).
 * Settings that belong to one account (ACCOUNT_KEYS) live in UserSetting.
 *
 * Secret keys (see SECRET_KEYS) are encrypted at rest with APP_KEY, cached
 * only in their encrypted form, never exported, and only ever leave the
 * server as a mask (see maskValue()). Everything that reads a secret must go
 * through Setting::get() / UserSetting::get(); everything that ships
 * settings to a browser must go through publicView().
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    /**
     * Settings that hold credentials. Credentials of alert destinations live
     * with the destination (NotificationDestination).
     */
    public const SECRET_KEYS = [
        'twitch_client_secret',
        'smtp_password',
    ];

    /** Keys the application manages itself; never exported or imported. */
    public const INTERNAL_KEYS = [
        'last_sync_at',
        'setup_token_hash',
        'setup_token_expires_at',
    ];

    /** Installation-wide settings; only administrators can read or write them. */
    public const SERVER_KEYS = [
        'twitch_client_id',
        'twitch_client_secret',
        'auto_sync_enabled',
        'sync_frequency_minutes',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    /**
     * Settings every account has for itself (stored in UserSetting): default
     * filters, appearance and history retention. Where its alerts go is a list
     * of NotificationDestination records.
     */
    public const ACCOUNT_KEYS = [
        'global_min_viewers',
        'global_min_avg_viewers',
        'global_languages',
        'global_keywords',
        'theme',
        'history_retention_days',
    ];

    /** Everything the Settings page can read or write. */
    public const MANAGED_KEYS = [...self::SERVER_KEYS, ...self::ACCOUNT_KEYS];

    /**
     * Values in effect while nothing is stored. The Settings page shows these
     * and the application uses them, so the two always agree. There is no
     * mail server by default: e-mail stays unavailable until an
     * administrator enters one.
     */
    public const DEFAULTS = [
        'sync_frequency_minutes' => '5',
        'smtp_port' => '587',
        'mail_from_name' => 'StreamRadar',
        'global_min_viewers' => '0',
        'global_min_avg_viewers' => '0',
        'global_languages' => '[]',
        'global_keywords' => '[]',
        'theme' => 'system',
        'history_retention_days' => '0',
    ];

    public static function isAccountKey(string $key): bool
    {
        return in_array($key, self::ACCOUNT_KEYS, true);
    }

    /**
     * Store managed settings on behalf of an account: its own settings go to
     * the account, installation settings to the shared store, and the latter
     * only ever for an administrator. Anything else is ignored.
     *
     * @param  array<string, mixed>  $data
     */
    public static function storeFor(User $user, array $data): void
    {
        foreach ($data as $key => $value) {
            if (self::isAccountKey($key)) {
                UserSetting::set($user, $key, $value);
            } elseif ($user->isAdmin() && in_array($key, self::SERVER_KEYS, true)) {
                static::set($key, $value);
            }
        }
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /** Managed keys that are safe to export / display verbatim. */
    public static function exportableKeys(): array
    {
        return array_values(array_diff(self::MANAGED_KEYS, self::SECRET_KEYS));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever("setting.{$key}", function () use ($key) {
            $setting = static::where('key', $key)->first();

            // Cache a sentinel for "absent" so the default can vary per call.
            return $setting ? ['v' => $setting->value] : ['absent' => true];
        });

        if (isset($stored['absent']) || $stored['v'] === null) {
            return $default;
        }

        return self::fromStorage($key, $stored['v']) ?? $default;
    }

    /** The value in effect: the stored one, else the built-in default. */
    public static function effective(string $key): mixed
    {
        return static::get($key, self::DEFAULTS[$key] ?? null);
    }

    /** Minutes between automatic scans. The only place its default is read. */
    public static function syncFrequencyMinutes(): int
    {
        return max(1, (int) static::effective('sync_frequency_minutes'));
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => self::toStorage($key, $value)],
        );

        Cache::forget("setting.{$key}");
    }

    /** Encrypt secrets on the way into the database. */
    public static function toStorage(string $key, mixed $value): mixed
    {
        if (self::isSecret($key) && $value !== null && $value !== '') {
            return Crypt::encryptString((string) $value);
        }

        return $value;
    }

    /** Decrypt secrets on the way out; null when a secret cannot be decrypted. */
    public static function fromStorage(string $key, mixed $value): mixed
    {
        if ($value === null || ! self::isSecret($key)) {
            return $value;
        }

        $plain = Secrets::tryDecrypt((string) $value);
        if ($plain === null) {
            Log::error("Setting [{$key}] could not be decrypted (APP_KEY changed?). Re-enter it in Settings.");
        }

        return $plain;
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
        Cache::forget("setting.{$key}");
    }

    public static function getMany(array $keys, array $defaults = []): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = static::get($key, $defaults[$key] ?? null);
        }

        return $result;
    }

    public static function has(string $key): bool
    {
        $v = static::get($key);

        return $v !== null && $v !== '';
    }

    /**
     * A display-only representation of a secret: never the value itself.
     * URLs keep scheme + host so the user can recognise the target.
     */
    public static function maskValue(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (str_ends_with($key, '_url')) {
            $parts = parse_url($value);
            if (! empty($parts['host'])) {
                return ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/••••'.substr($value, -4);
            }
        }

        return str_repeat('•', 8).substr($value, -4);
    }

    /**
     * Settings shaped for a browser: plain values for non-secrets, and for
     * every secret only `<key>_set` (bool) and `<key>_masked` (string|null).
     */
    public static function publicView(array $keys, array $defaults = []): array
    {
        return self::browserView($keys, fn (string $key) => static::get($key, $defaults[$key] ?? null));
    }

    /**
     * The browser shape of publicView(), for any store: $read returns the
     * value of one key. Shared with UserSetting::publicView().
     *
     * @param  string[]  $keys
     * @param  Closure(string): mixed  $read
     * @return array<string, mixed>
     */
    public static function browserView(array $keys, Closure $read): array
    {
        $view = [];
        foreach ($keys as $key) {
            $value = $read($key);

            if (self::isSecret($key)) {
                $view[$key.'_set'] = $value !== null && $value !== '';
                $view[$key.'_masked'] = self::maskValue($key, $value);

                continue;
            }

            $view[$key] = $value;
        }

        return $view;
    }
}
