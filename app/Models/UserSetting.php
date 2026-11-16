<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Settings of one account (Setting::ACCOUNT_KEYS): default filters,
 * appearance, and where that account's alerts are delivered.
 *
 * Secrets follow the same rules as in Setting: encrypted at rest, only ever
 * sent to a browser as `<key>_set` / `<key>_masked`, never exported.
 */
class UserSetting extends Model
{
    protected $fillable = ['user_id', 'key', 'value'];

    /** @var string[]|null Decrypted secrets of all accounts, for log redaction. */
    private static ?array $secretValues = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function get(User|int $user, string $key, mixed $default = null): mixed
    {
        $value = static::where('user_id', self::id($user))->where('key', $key)->value('value');

        if ($value === null) {
            return $default;
        }

        return Setting::fromStorage($key, $value) ?? $default;
    }

    public static function set(User|int $user, string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['user_id' => self::id($user), 'key' => $key],
            ['value' => Setting::toStorage($key, $value)],
        );

        self::$secretValues = null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function setMany(User|int $user, array $settings): void
    {
        foreach ($settings as $key => $value) {
            static::set($user, $key, $value);
        }
    }

    public static function forget(User|int $user, string $key): void
    {
        static::where('user_id', self::id($user))->where('key', $key)->delete();

        self::$secretValues = null;
    }

    public static function has(User|int $user, string $key): bool
    {
        $value = static::get($user, $key);

        return $value !== null && $value !== '';
    }

    /**
     * @param  string[]  $keys
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function getMany(User|int $user, array $keys, array $defaults = []): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = static::get($user, $key, $defaults[$key] ?? null);
        }

        return $result;
    }

    /**
     * Settings shaped for a browser: plain values for non-secrets, and for
     * every secret only `<key>_set` (bool) and `<key>_masked` (string|null).
     *
     * @param  string[]  $keys
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function publicView(User|int $user, array $keys, array $defaults = []): array
    {
        $view = [];
        foreach ($keys as $key) {
            $value = static::get($user, $key, $defaults[$key] ?? null);

            if (Setting::isSecret($key)) {
                $view[$key.'_set'] = $value !== null && $value !== '';
                $view[$key.'_masked'] = Setting::maskValue($key, $value);

                continue;
            }

            $view[$key] = $value;
        }

        return $view;
    }

    /**
     * Current secrets of every account, so the log redactor can scrub them
     * wherever they appear.
     *
     * @return string[]
     */
    public static function secretValues(): array
    {
        if (self::$secretValues !== null) {
            return self::$secretValues;
        }

        $values = [];
        foreach (static::whereIn('key', Setting::SECRET_KEYS)->whereNotNull('value')->get(['key', 'value']) as $row) {
            $value = Setting::fromStorage($row->key, $row->value);
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return self::$secretValues = array_values(array_unique($values));
    }

    public static function flushSecretCache(): void
    {
        self::$secretValues = null;
    }

    private static function id(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }
}
