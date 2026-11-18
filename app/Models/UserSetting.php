<?php

namespace App\Models;

use App\Models\Concerns\CachesSecretValues;
use App\Support\SettingValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Settings of one account (Setting::ACCOUNT_KEYS): default filters and
 * appearance.
 *
 * Secrets follow the same rules as in Setting: encrypted at rest, only ever
 * sent to a browser as `<key>_set` / `<key>_masked`, never exported.
 */
class UserSetting extends Model
{
    use CachesSecretValues;

    protected $fillable = ['user_id', 'key', 'value'];

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

        static::flushSecretCache();
    }

    public static function forget(User|int $user, string $key): void
    {
        static::where('user_id', self::id($user))->where('key', $key)->delete();

        static::flushSecretCache();
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
     * The account's default stream filters (Settings → Global Filters).
     *
     * @return array{min_viewers: int, min_avg_viewers: int, languages: array<int, mixed>, keywords: array<int, mixed>}
     */
    public static function defaultFilters(User|int $user): array
    {
        $values = static::getMany($user, ['global_min_viewers', 'global_min_avg_viewers', 'global_languages', 'global_keywords'], Setting::DEFAULTS);

        return [
            'min_viewers' => (int) $values['global_min_viewers'],
            'min_avg_viewers' => (int) $values['global_min_avg_viewers'],
            'languages' => SettingValue::decodeList($values['global_languages']),
            'keywords' => SettingValue::decodeList($values['global_keywords']),
        ];
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
        return Setting::browserView($keys, fn (string $key) => static::get($user, $key, $defaults[$key] ?? null));
    }

    protected static function collectSecretValues(): iterable
    {
        foreach (static::whereIn('key', Setting::SECRET_KEYS)->whereNotNull('value')->get(['key', 'value']) as $row) {
            yield Setting::fromStorage($row->key, $row->value);
        }
    }

    private static function id(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }
}
