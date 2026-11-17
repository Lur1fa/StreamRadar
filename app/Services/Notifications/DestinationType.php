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
     * Plain settings. For a webhook, `headers` and `query` hold the names
     * only; their values are secrets.
     *
     * @return string[]
     */
    public function configKeys(): array
    {
        return match ($this) {
            self::Ntfy => ['server_url', 'topic', 'username', 'title_prefix'],
            self::Webhook => ['method', 'auth', 'auth_username', 'headers', 'query'],
            self::Email => ['to', 'subject_prefix'],
            self::Telegram => ['chat_id'],
            self::Discord => [],
        };
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

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
