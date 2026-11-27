<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\User;
use App\Support\SettingValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Single source of validation rules for managed settings, shared by the
 * Settings page (update) and the configuration import.
 *
 * Every account may write its own settings (Setting::ACCOUNT_KEYS). The
 * installation's settings (Setting::SERVER_KEYS) are only accepted from
 * administrators; from anyone else they are dropped before validation, so
 * they can neither be changed nor probed.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return string[]
     */
    public static function writableKeys(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->isAdmin() ? Setting::MANAGED_KEYS : Setting::ACCOUNT_KEYS;
    }

    public function rules(): array
    {
        return self::settingRules();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function settingRules(): array
    {
        return [
            'twitch_client_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'twitch_client_secret' => ['nullable', 'string', 'max:255'],
            'auto_sync_enabled' => ['nullable', 'boolean'],
            'sync_frequency_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'global_min_viewers' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'global_min_avg_viewers' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'global_languages' => ['nullable', 'json', 'max:2000'],
            'global_keywords' => ['nullable', 'json', 'max:5000'],
            'theme' => ['nullable', 'in:light,dark,system'],
            'history_retention_days' => ['nullable', 'integer', 'in:0,7,30,90,365'],
            'smtp_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl,starttls'],
            'mail_from_address' => ['nullable', 'email:rfc', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public const BOOLEAN_KEYS = [
        'auto_sync_enabled',
    ];

    /**
     * Drop masked placeholders so a secret is never overwritten by its mask,
     * and ignore anything that is not a managed key.
     */
    protected function prepareForValidation(): void
    {
        $writable = self::writableKeys($this->user());

        $clean = [];
        foreach ($writable as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (Setting::isSecret($key) && self::looksMasked($value)) {
                continue;
            }
            $clean[$key] = $value;
        }

        $this->replace($clean + ['clear_secrets' => array_values(array_intersect((array) $this->input('clear_secrets', []), Setting::SECRET_KEYS, $writable))]);
    }

    public static function looksMasked(mixed $value): bool
    {
        return is_string($value) && (str_starts_with($value, '***') || str_starts_with($value, '•') || str_contains($value, '/••••'));
    }

    /**
     * Normalise validated input into storable strings.
     *
     * @return array<string, string|null>
     */
    public static function normalize(array $validated): array
    {
        $out = [];
        foreach ($validated as $key => $value) {
            if ($key === 'clear_secrets') {
                continue;
            }
            if ($value === null) {
                if (! Setting::isSecret($key)) {
                    $out[$key] = null;
                }

                continue;
            }
            $out[$key] = in_array($key, self::BOOLEAN_KEYS, true) ? SettingValue::flag($value) : SettingValue::encode($value);
        }

        return $out;
    }
}
