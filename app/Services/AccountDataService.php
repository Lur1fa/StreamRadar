<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything an account owns, handled as one unit: adopting data that has no
 * owner yet, moving it between accounts, and deleting it.
 */
class AccountDataService
{
    /** What an account configures. A target account must not have any of this before data is moved into it. */
    public const CONFIG_TABLES = ['categories', 'tag_filters', 'tracked_channels', 'blacklist_rules', 'alert_rules'];

    /** What the sync derives from that configuration. */
    public const DERIVED_TABLES = ['streams', 'history_events'];

    public const TABLES = [...self::CONFIG_TABLES, ...self::DERIVED_TABLES];

    public function hasOrphans(): bool
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNull('owner_id')->exists()) {
                return true;
            }
        }

        return DB::table('settings')->whereIn('key', Setting::ACCOUNT_KEYS)->exists();
    }

    /**
     * Give data without an owner to an account. Such data only exists when
     * the installation was upgraded from the time everything was shared:
     * rows with no owner, and per-account settings still in the shared table.
     *
     * @return array<string, int> rows adopted per table
     */
    public function adoptOrphans(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $counts = [];

            foreach (self::TABLES as $table) {
                $counts[$table] = DB::table($table)->whereNull('owner_id')->update(['owner_id' => $user->id]);
            }

            // Values are copied as stored, so secrets stay encrypted throughout.
            $legacy = DB::table('settings')->whereIn('key', Setting::ACCOUNT_KEYS)->get();
            foreach ($legacy as $row) {
                $exists = DB::table('user_settings')->where('user_id', $user->id)->where('key', $row->key)->exists();
                if (! $exists) {
                    DB::table('user_settings')->insert([
                        'user_id' => $user->id,
                        'key' => $row->key,
                        'value' => $row->value,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }
                Cache::forget("setting.{$row->key}");
            }
            DB::table('settings')->whereIn('key', Setting::ACCOUNT_KEYS)->delete();
            $counts['settings'] = $legacy->count();

            UserSetting::flushSecretCache();

            return $counts;
        });
    }

    /**
     * @return array<string, int> rows per table
     */
    public function counts(User $user): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->where('owner_id', $user->id)->count();
        }
        $counts['settings'] = DB::table('user_settings')->where('user_id', $user->id)->count();

        return $counts;
    }

    /** Whether the account has tracking, alert or blacklist configuration of its own. */
    public function hasConfiguration(User $user): bool
    {
        foreach (self::CONFIG_TABLES as $table) {
            if (DB::table($table)->where('owner_id', $user->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move everything one account owns to another account. The source ends
     * up empty. The target must not have configuration of its own unless
     * $replace is set, in which case its data is deleted first.
     *
     * @return array<string, int> rows moved per table
     */
    public function move(User $from, User $to, bool $replace = false): array
    {
        if ($from->is($to)) {
            throw new \InvalidArgumentException('Source and target are the same account.');
        }

        if (! $replace && $this->hasConfiguration($to)) {
            throw new \RuntimeException('The target account already has tracking data.');
        }

        return DB::transaction(function () use ($from, $to, $replace) {
            if ($replace) {
                $this->purge($to, includingSettings: false);
            } else {
                // Derived rows of an account without configuration are leftovers.
                DB::table('streams')->where('owner_id', $to->id)->delete();
            }

            $counts = [];
            foreach (self::TABLES as $table) {
                $counts[$table] = DB::table($table)->where('owner_id', $from->id)->update(['owner_id' => $to->id]);
            }

            // The source's settings win key by key; other settings of the target stay.
            $keys = DB::table('user_settings')->where('user_id', $from->id)->pluck('key');
            DB::table('user_settings')->where('user_id', $to->id)->whereIn('key', $keys)->delete();
            $counts['settings'] = DB::table('user_settings')->where('user_id', $from->id)->update(['user_id' => $to->id]);

            UserSetting::flushSecretCache();

            return $counts;
        });
    }

    public function purge(User $user, bool $includingSettings = true): void
    {
        DB::transaction(function () use ($user, $includingSettings) {
            // stream_alert_tracking rows go with their alert rule (cascade).
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->where('owner_id', $user->id)->delete();
            }

            if ($includingSettings) {
                DB::table('user_settings')->where('user_id', $user->id)->delete();
                UserSetting::flushSecretCache();
            }
        });
    }
}
