<?php

namespace App\Services\Notifications;

use App\Models\NotificationDestination;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Until 2026-11-17 an account had exactly one destination per channel, kept
 * as settings keys. This turns those settings into destinations. Used by
 * the upgrade migration, when an administrator adopts settings left from
 * the time everything was shared, and when an old backup is imported.
 */
final class LegacyDestinations
{
    public const KEYS = [
        'mail_to',
        'discord_webhook_url',
        'telegram_bot_token',
        'telegram_chat_id',
        'webhook_url',
        'webhook_format',
        'notifications_email_enabled',
        'notifications_discord_enabled',
        'notifications_telegram_enabled',
        'notifications_webhook_enabled',
    ];

    /** Keys that were stored encrypted with APP_KEY (Crypt::encryptString). */
    public const SECRET_KEYS = ['discord_webhook_url', 'telegram_bot_token', 'webhook_url'];

    /** The plain value of an old setting as stored in the database. */
    public static function decode(string $key, mixed $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (! in_array($key, self::SECRET_KEYS, true)) {
            return (string) $stored;
        }

        try {
            return Crypt::decryptString((string) $stored);
        } catch (DecryptException) {
            Log::warning("The old setting [{$key}] could not be decrypted (APP_KEY changed?), so it was not turned into a destination.");

            return null;
        }
    }

    /**
     * Create the destinations the old settings of one account describe.
     *
     * @param  array<string, mixed>  $values  plain (decrypted) values by key
     * @return int destinations created
     */
    public static function import(int $ownerId, array $values): int
    {
        $value = fn (string $key) => is_string($values[$key] ?? null) && trim($values[$key]) !== '' ? trim($values[$key]) : null;
        $enabled = fn (string $channel) => (string) ($values["notifications_{$channel}_enabled"] ?? '1') !== '0';
        $created = 0;

        $create = function (DestinationType $type, string $name, bool $enabled, array $config, array $secrets = []) use ($ownerId, &$created) {
            NotificationDestination::createFor($ownerId, [
                'name' => $name,
                'type' => $type,
                'enabled' => $enabled,
                'config' => $config,
                'secrets' => $secrets,
            ]);
            $created++;
        };

        if ($to = $value('mail_to')) {
            // An old backup imported twice must not add the address twice.
            $exists = NotificationDestination::ownedBy($ownerId)->where('type', DestinationType::Email->value)->get()
                ->contains(fn (NotificationDestination $d) => ($d->config['to'] ?? null) === $to);

            if (! $exists) {
                $create(DestinationType::Email, 'Email', $enabled('email'), ['to' => $to, 'subject_prefix' => '']);
            }
        }

        if ($url = $value('discord_webhook_url')) {
            $create(DestinationType::Discord, 'Discord', $enabled('discord'), [], ['webhook_url' => $url]);
        }

        if (($token = $value('telegram_bot_token')) && ($chatId = $value('telegram_chat_id'))) {
            $create(DestinationType::Telegram, 'Telegram', $enabled('telegram'), ['chat_id' => $chatId], ['bot_token' => $token]);
        }

        if ($url = $value('webhook_url')) {
            $ntfy = ($values['webhook_format'] ?? 'json') === 'ntfy' ? self::splitNtfyUrl($url) : null;

            if ($ntfy !== null) {
                $create(DestinationType::Ntfy, "ntfy · {$ntfy['topic']}", $enabled('webhook'), $ntfy + ['username' => '', 'title_prefix' => '']);

                // Rules that went to the ntfy-formatted webhook now go to ntfy.
                DB::table('alert_rules')->where('owner_id', $ownerId)->where('notify_webhook', true)
                    ->update(['notify_ntfy' => true, 'notify_webhook' => false]);
            } else {
                $create(DestinationType::Webhook, 'Webhook', $enabled('webhook'),
                    ['method' => 'POST', 'auth' => 'none', 'auth_username' => '', 'headers' => [], 'query' => []],
                    ['url' => $url]);
            }
        }

        return $created;
    }

    /**
     * Split an ntfy topic URL (https://ntfy.example.com/alerts) into server
     * and topic. Null when it is not a plain topic URL.
     *
     * @return array{server_url: string, topic: string}|null
     */
    public static function splitNtfyUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn (string $s) => $s !== ''));
        $topic = array_pop($segments);

        if ($topic === null || ! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $topic)) {
            return null;
        }

        $server = strtolower($parts['scheme']).'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($segments !== [] ? '/'.implode('/', $segments) : '');

        return ['server_url' => $server, 'topic' => $topic];
    }
}
