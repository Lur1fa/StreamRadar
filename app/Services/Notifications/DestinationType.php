<?php

namespace App\Services\Notifications;

/**
 * The kinds of places an account can send its alerts to. Each destination
 * has plain settings (`config`, shown back in the form) and secrets (stored
 * encrypted, never sent to a browser).
 */
enum DestinationType: string
{
    case Ntfy = 'ntfy';
    case Webhook = 'webhook';
    case Email = 'email';
    case Telegram = 'telegram';
    case Discord = 'discord';

    /** A bot token from @BotFather: 123456789:ABC… */
    public const TELEGRAM_BOT_TOKEN = '/^\d{3,}:[A-Za-z0-9_\-]{10,}$/';

    /** A Telegram chat id, negative for groups and channels. */
    public const TELEGRAM_CHAT_ID = '/^-?\d+$/';

    public const NTFY_TOPIC = '/^[A-Za-z0-9_\-]{1,64}$/';

    public function label(): string
    {
        return match ($this) {
            self::Ntfy => 'ntfy',
            self::Webhook => 'Webhook',
            self::Email => 'Email',
            self::Telegram => 'Telegram',
            self::Discord => 'Discord',
        };
    }

    /** The alert rule switch that sends a rule's alerts to destinations of this type. */
    public function ruleFlag(): string
    {
        return 'notify_'.$this->value;
    }

    /**
     * Every alert rule switch: notify_ntfy, notify_webhook, …
     *
     * @return string[]
     */
    public static function ruleFlags(): array
    {
        return array_map(fn (self $type) => $type->ruleFlag(), self::cases());
    }

    /**
     * Plain settings. For a webhook, `headers` and `query` hold the names
     * only; their values are secrets.
     *
     * @return string[]
     */
    public function configKeys(): array
    {
        return array_keys($this->defaultConfig());
    }

    /**
     * Plain settings with the values a new destination starts with.
     *
     * @return array<string, string|array<int, string>>
     */
    public function defaultConfig(): array
    {
        return match ($this) {
            self::Ntfy => ['server_url' => '', 'topic' => '', 'username' => '', 'title_prefix' => ''],
            self::Webhook => ['method' => 'POST', 'auth' => 'none', 'auth_username' => '', 'headers' => [], 'query' => []],
            self::Email => ['to' => '', 'subject_prefix' => ''],
            self::Telegram => ['chat_id' => ''],
            self::Discord => [],
        };
    }

    /**
     * Only this type's plain settings: lists stay lists of strings, every
     * other value becomes a string (its default when it is not a scalar).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string|array<int, string>>
     */
    public function normalizeConfig(array $config): array
    {
        $normalized = [];
        foreach ($this->defaultConfig() as $key => $default) {
            $value = $config[$key] ?? null;
            $normalized[$key] = is_array($default)
                ? array_values(array_filter((array) $value, 'is_string'))
                : (is_scalar($value) ? (string) $value : $default);
        }

        return $normalized;
    }

    /**
     * Secrets that hold one value. A webhook also keeps its header and
     * query values as secrets (maps keyed by name).
     *
     * @return string[]
     */
    public function secretKeys(): array
    {
        return match ($this) {
            self::Ntfy => ['access_token', 'password'],
            self::Webhook => ['url', 'auth_password', 'auth_token'],
            self::Email => [],
            self::Telegram => ['bot_token'],
            self::Discord => ['webhook_url'],
        };
    }

    /**
     * Every type as the frontend needs it, so the pages never keep their
     * own copy of the list.
     *
     * @return array<int, array{value: string, label: string, flag: string, defaultConfig: array<string, mixed>}>
     */
    public static function forBrowser(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'flag' => $type->ruleFlag(),
            'defaultConfig' => (object) $type->defaultConfig(),
        ], self::cases());
    }
}
