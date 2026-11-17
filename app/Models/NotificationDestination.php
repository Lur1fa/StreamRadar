<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Services\Notifications\DestinationType;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * One place an account's alerts are delivered to (see DestinationType).
 *
 * Secrets (tokens, passwords, bearer-style URLs, webhook header and query
 * values) are encrypted together with APP_KEY in `secrets`, hidden from
 * serialisation, never exported, and only ever reach a browser as "is set"
 * flags and masks (see toBrowser()).
 *
 * @property int $id
 * @property string $name
 * @property DestinationType $type
 * @property bool $enabled
 * @property array<string, mixed>|null $config
 * @property array<string, mixed> $secrets
 */
class NotificationDestination extends Model
{
    use BelongsToOwner, HasFactory;

    protected $fillable = ['name', 'type', 'enabled', 'config', 'secrets'];

    protected $hidden = ['secrets'];

    /** Headers a webhook may not set: the HTTP client owns them. */
    public const RESERVED_HEADERS = [
        'host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'keep-alive',
        'upgrade', 'te', 'trailer', 'expect', 'proxy-authorization', 'proxy-connection',
    ];

    /** @var string[]|null Decrypted secrets of all accounts, for log redaction. */
    private static ?array $secretValues = null;

    protected function casts(): array
    {
        return [
            'type' => DestinationType::class,
            'enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$secretValues = null);
        static::deleted(fn () => self::$secretValues = null);
    }

    protected function secrets(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                try {
                    $decoded = json_decode(Crypt::decryptString($value), true);

                    return is_array($decoded) ? $decoded : [];
                } catch (DecryptException) {
                    Log::error("Notification destination [{$this->getKey()}] could not be decrypted (APP_KEY changed?). Re-enter its secrets in Settings.");

                    return [];
                }
            },
            set: function (?array $value): ?string {
                $clean = self::withoutEmpty($value ?? []);

                return $clean === [] ? null : Crypt::encryptString((string) json_encode($clean));
            },
        );
    }

    public function secret(string $key): ?string
    {
        $value = $this->secrets[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Webhook headers, name => value, in the configured order.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->pairs('headers');
    }

    /**
     * Webhook query parameters, name => value, in the configured order.
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        return $this->pairs('query');
    }

    public static function isReservedHeader(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED_HEADERS, true);
    }

    /**
     * Recipients of an e-mail destination.
     *
     * @return string[]
     */
    public function recipients(): array
    {
        return self::splitAddresses((string) ($this->config['to'] ?? ''));
    }

    /**
     * @return string[]
     */
    public static function splitAddresses(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', $value) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));
    }

    /**
     * What still has to be filled in before this destination can deliver.
     * A destination imported from a backup, for example, has no secrets.
     *
     * @return string[] field keys
     */
    public function missing(): array
    {
        $config = $this->config ?? [];
        $filled = fn (string $key) => is_string($config[$key] ?? null) && trim($config[$key]) !== '';
        $stored = fn (string $key) => $this->secret($key) !== null;

        $required = match ($this->type) {
            DestinationType::Ntfy => [
                'server_url' => $filled('server_url'),
                'topic' => $filled('topic'),
                'password' => ! $filled('username') || $stored('password'),
            ],
            DestinationType::Webhook => [
                'url' => $stored('url'),
                'auth_username' => ($config['auth'] ?? 'none') !== 'basic' || $filled('auth_username'),
                'auth_password' => ($config['auth'] ?? 'none') !== 'basic' || $stored('auth_password'),
                'auth_token' => ($config['auth'] ?? 'none') !== 'bearer' || $stored('auth_token'),
                // Every configured header and query parameter needs its value.
                'headers' => count($this->pairs('headers')) === count((array) ($config['headers'] ?? [])),
                'query' => count($this->pairs('query')) === count((array) ($config['query'] ?? [])),
            ],
            DestinationType::Email => ['to' => $this->recipients() !== []],
            DestinationType::Telegram => ['bot_token' => $stored('bot_token'), 'chat_id' => $filled('chat_id')],
            DestinationType::Discord => ['webhook_url' => $stored('webhook_url')],
        };

        return array_keys(array_filter($required, fn (bool $ok) => ! $ok));
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The plain settings of this destination's type, with defaults.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $defaults = match ($this->type) {
            DestinationType::Webhook => ['method' => 'POST', 'auth' => 'none', 'auth_username' => '', 'headers' => [], 'query' => []],
            default => array_fill_keys($this->type->configKeys(), ''),
        };

        $config = $this->config ?? [];
        $public = [];
        foreach ($defaults as $key => $default) {
            $value = $config[$key] ?? null;
            $public[$key] = is_array($default) ? array_values(array_filter((array) $value, 'is_string')) : (is_scalar($value) ? (string) $value : $default);
        }

        return $public;
    }

    /**
     * The destination shaped for a browser: plain settings, and for every
     * secret only whether it is stored (plus a mask for URLs).
     *
     * @return array<string, mixed>
     */
    public function toBrowser(): array
    {
        $set = [];
        $masks = [];
        foreach ($this->type->secretKeys() as $key) {
            $value = $this->secret($key);
            $set[$key] = $value !== null;

            if ($value !== null && in_array($key, ['url', 'webhook_url'], true)) {
                $masks[$key] = Setting::maskValue('webhook_url', $value);
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'enabled' => (bool) $this->enabled,
            'config' => $this->publicConfig(),
            'secrets_set' => $set,
            'secret_masks' => $masks,
            'missing' => $this->missing(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Current secrets of every destination of every account, so the log
     * redactor can scrub them wherever they appear.
     *
     * @return string[]
     */
    public static function secretValues(): array
    {
        if (self::$secretValues !== null) {
            return self::$secretValues;
        }

        $values = [];
        foreach (static::query()->whereNotNull('secrets')->get() as $destination) {
            array_walk_recursive($destination->secrets, function ($value) use (&$values) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            });
        }

        return self::$secretValues = array_values(array_unique($values));
    }

    public static function flushSecretCache(): void
    {
        self::$secretValues = null;
    }

    /**
     * @return array<string, string>
     */
    private function pairs(string $key): array
    {
        $values = (array) ($this->secrets[$key] ?? []);
        $pairs = [];

        foreach ((array) ($this->config[$key] ?? []) as $name) {
            if (is_string($name) && isset($values[$name]) && is_string($values[$name]) && $values[$name] !== '') {
                $pairs[$name] = $values[$name];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = array_filter($value, fn ($v) => is_string($v) && $v !== '');
                if ($value !== []) {
                    $clean[$key] = $value;
                }
            } elseif (is_string($value) && $value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
