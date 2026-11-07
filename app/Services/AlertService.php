<?php

namespace App\Services;

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    /**
     * @param  bool  $silent  If true, records tracking but does not trigger notifications (used for initial sync)
     * @return array<array{rule: AlertRule, stream: Stream}>
     */
    public function checkAlerts(Stream $stream, ?Stream $oldStream, bool $silent = false): array
    {
        $triggeredAlerts = [];
        $rules = AlertRule::where('is_active', true)->get();

        foreach ($rules as $rule) {
            if (! $rule->matchesStream($stream)) {
                continue;
            }

            $isNewStream = ! $oldStream;

            if ($isNewStream && ! $rule->notify_on_stream_start) {
                continue;
            }

            if ($oldStream && $rule->matchesStream($oldStream)) {
                $categoryChanged = $rule->notify_on_category_change
                    && ($oldStream->game_name !== $stream->game_name);

                if (! $categoryChanged) {
                    continue;
                }
            }

            if ($rule->match_mode === 'first_time') {
                $alreadyTriggered = StreamAlertTracking::where('alert_rule_id', $rule->id)
                    ->where('stream_twitch_id', $stream->twitch_id)
                    ->exists();

                if ($alreadyTriggered) {
                    continue;
                }
            }

            if ($silent) {
                StreamAlertTracking::updateOrCreate(
                    ['alert_rule_id' => $rule->id, 'stream_twitch_id' => $stream->twitch_id],
                    ['streamer_login' => $stream->user_login],
                );
                continue;
            }

            StreamAlertTracking::updateOrCreate(
                ['alert_rule_id' => $rule->id, 'stream_twitch_id' => $stream->twitch_id],
                ['streamer_login' => $stream->user_login, 'triggered_at' => now()],
            );

            $triggeredAlerts[] = [
                'rule' => $rule,
                'stream' => $stream,
            ];
        }

        if (! empty($triggeredAlerts)) {
            $ruleNames = array_map(fn ($a) => $a['rule']->name, $triggeredAlerts);
            $ruleIds = array_map(fn ($a) => $a['rule']->id, $triggeredAlerts);

            HistoryEvent::create([
                'type' => 'alert_triggered',
                'stream_twitch_id' => $stream->twitch_id,
                'streamer_login' => $stream->user_login,
                'streamer_name' => $stream->user_name,
                'category_name' => $stream->game_name ?? $stream->category?->name,
                'title' => $stream->title,
                'viewer_count' => $stream->viewer_count,
                'profile_image_url' => $stream->profile_image_url,
                'metadata' => ['rule_names' => $ruleNames, 'rule_ids' => $ruleIds],
            ]);
        }

        return $triggeredAlerts;
    }

    public function seedTrackingForRule(AlertRule $rule): void
    {
        $streams = Stream::all();

        foreach ($streams as $stream) {
            if (! $rule->matchesStream($stream)) {
                continue;
            }

            StreamAlertTracking::updateOrCreate(
                ['alert_rule_id' => $rule->id, 'stream_twitch_id' => $stream->twitch_id],
                ['streamer_login' => $stream->user_login],
            );
        }
    }

    /**
     * @param  array<array{rule: AlertRule, stream: Stream}>  $allTriggered
     */
    public function sendNotifications(array $allTriggered): void
    {
        if (empty($allTriggered)) {
            return;
        }

        if (Setting::get('notifications_email_enabled', '1') !== '0') {
            $this->sendEmailAlerts($allTriggered);
        }
        if (Setting::get('notifications_discord_enabled', '1') !== '0') {
            $this->sendDiscordAlerts($allTriggered);
        }
        if (Setting::get('notifications_telegram_enabled', '1') !== '0') {
            $this->sendTelegramAlerts($allTriggered);
        }
        if (Setting::get('notifications_webhook_enabled', '1') !== '0') {
            $this->sendWebhookAlerts($allTriggered);
        }
    }

    /**
     * @return array<array{stream: Stream, rules: string[]}>
     */
    private function groupByStream(array $alerts): array
    {
        $grouped = [];
        foreach ($alerts as $alert) {
            $key = $alert['stream']->twitch_id;
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['stream' => $alert['stream'], 'rules' => []];
            }
            $grouped[$key]['rules'][] = $alert['rule']->name;
        }

        return array_values($grouped);
    }

    /**
     * Build a presentation-ready data array for a grouped stream entry.
     */
    private function presentStream(array $entry): array
    {
        $stream = $entry['stream'];
        $categoryBoxArt = $stream->game_box_art_url
            ? str_replace(['{width}', '{height}'], ['188', '250'], $stream->game_box_art_url)
            : $stream->category?->getBoxArtUrlSized(188, 250);

        return [
            'stream' => $stream,
            'rules' => implode(', ', $entry['rules']),
            'url' => $stream->getTwitchUrl(),
            'viewers' => number_format($stream->viewer_count),
            'category' => $stream->game_name ?? $stream->category?->name ?? 'Unknown',
            'language' => strtoupper($stream->language ?? '?'),
            'avatar' => $stream->profile_image_url,
            'thumbnail' => $stream->getThumbnailUrlSized(440, 248),
            'category_box_art' => $categoryBoxArt,
        ];
    }

    // ── Discord ──────────────────────────────────────────────────────────

    private function sendDiscordAlerts(array $alerts): void
    {
        $webhookUrl = Setting::get('discord_webhook_url');
        if (! $webhookUrl) {
            return;
        }

        $discordAlerts = array_filter($alerts, fn ($a) => $a['rule']->notify_discord);
        if (empty($discordAlerts)) {
            return;
        }

        $embeds = [];
        foreach ($this->groupByStream($discordAlerts) as $entry) {
            $p = $this->presentStream($entry);

            $embed = [
                'title' => $p['stream']->title,
                'url' => $p['url'],
                'color' => 0x9147ff,
                'author' => [
                    'name' => "{$p['stream']->user_name} is live!",
                    'url' => $p['url'],
                ],
                'fields' => [
                    ['name' => 'Category', 'value' => $p['category'], 'inline' => true],
                    ['name' => 'Viewers', 'value' => $p['viewers'], 'inline' => true],
                    ['name' => 'Language', 'value' => $p['language'], 'inline' => true],
                ],
                'footer' => ['text' => "Alert: {$p['rules']}"],
                'timestamp' => now()->toIso8601String(),
            ];

            if ($p['avatar']) {
                $embed['author']['icon_url'] = $p['avatar'];
            }
            if ($p['thumbnail']) {
                $embed['image'] = ['url' => $p['thumbnail']];
            }
            if ($p['category_box_art']) {
                $embed['thumbnail'] = ['url' => $p['category_box_art']];
            }

            $embeds[] = $embed;
        }

        foreach (array_chunk($embeds, 10) as $chunk) {
            try {
                Http::post($webhookUrl, ['embeds' => $chunk]);
            } catch (\Exception $e) {
                Log::warning("Failed to send Discord alert: {$e->getMessage()}");
            }
        }
    }

    // ── Telegram ─────────────────────────────────────────────────────────

    private function sendTelegramAlerts(array $alerts): void
    {
        $token = Setting::get('telegram_bot_token');
        $chatId = Setting::get('telegram_chat_id');
        if (! $token || ! $chatId) {
            return;
        }

        $telegramAlerts = array_filter($alerts, fn ($a) => $a['rule']->notify_telegram ?? false);
        if (empty($telegramAlerts)) {
            return;
        }

        $grouped = $this->groupByStream($telegramAlerts);
        $apiBase = "https://api.telegram.org/bot{$token}";

        foreach ($grouped as $entry) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $caption = "<b>{$s->user_name}</b> is live!\n"
                ."<a href=\"{$p['url']}\">{$s->title}</a>\n\n"
                ."🎮 {$p['category']}  ·  👁 {$p['viewers']}  ·  🌐 {$p['language']}\n"
                ."📋 Alert: {$p['rules']}";

            try {
                if ($p['thumbnail']) {
                    Http::post("{$apiBase}/sendPhoto", [
                        'chat_id' => $chatId,
                        'photo' => $p['thumbnail'],
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ]);
                } else {
                    Http::post("{$apiBase}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to send Telegram alert: {$e->getMessage()}");
            }
        }
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    private function sendWebhookAlerts(array $alerts): void
    {
        $webhookUrl = Setting::get('webhook_url');
        if (! $webhookUrl) {
            return;
        }

        $webhookAlerts = array_filter($alerts, fn ($a) => $a['rule']->notify_webhook);
        if (empty($webhookAlerts)) {
            return;
        }

        if (Setting::get('webhook_format', 'json') === 'ntfy') {
            $this->sendNtfyWebhook($webhookUrl, $webhookAlerts);
        } else {
            $this->sendJsonWebhook($webhookUrl, $webhookAlerts);
        }
    }

    private function sendJsonWebhook(string $webhookUrl, array $webhookAlerts): void {
        $payload = [];
        foreach ($this->groupByStream($webhookAlerts) as $entry) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $payload[] = [
                'event' => 'stream.alert',
                'rules' => $entry['rules'],
                'channel' => $s->user_name,
                'channel_login' => $s->user_login,
                'title' => $s->title,
                'game' => $p['category'],
                'viewers' => $s->viewer_count,
                'language' => $s->language,
                'url' => $p['url'],
                'thumbnail' => $p['thumbnail'],
                'avatar' => $p['avatar'],
                'category_box_art' => $p['category_box_art'],
                'started_at' => $s->started_at?->toIso8601String(),
                'is_mature' => $s->is_mature,
                'tags' => $s->tags,
            ];
        }

        try {
            Http::post($webhookUrl, [
                'event' => 'stream.alerts.batch',
                'count' => count($payload),
                'alerts' => $payload,
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to send webhook alert: {$e->getMessage()}");
        }
    }

    private function sendNtfyWebhook(string $webhookUrl, array $webhookAlerts): void
    {
        foreach ($this->groupByStream($webhookAlerts) as $entry) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $tags = $s->tags ? implode(', ', $s->tags) : null;

            $body = "{$s->title}\n"
                . "{$p['category']} · {$p['viewers']} viewers\n"
                . ($tags ? "Tags: {$tags}\n" : '')
                . "Alert: {$p['rules']}";

            $headers = [
                'Title' => '=?UTF-8?B?'.base64_encode("{$s->user_name} is live").'?=',
                'Click' => $p['url']
            ];

            if ($p['thumbnail']) {
                $headers['Attach'] = $p['thumbnail'] . '?t=' . time();
                $headers['Filename'] = 'preview.jpg';
            }
            if ($p['avatar']) {
                $headers['Icon'] = $p['avatar'];
            }

            try {
                Http::withHeaders($headers)
                    ->withBody($body, 'text/plain')
                    ->post($webhookUrl);
            } catch (\Exception $e) {
                Log::warning("Failed to send webhook alert: {$e->getMessage()}");
            }
        }
    }

    // ── Email ────────────────────────────────────────────────────────────

    private function sendEmailAlerts(array $alerts): void
    {
        $recipient = Setting::get('mail_to');
        if (! $recipient) {
            return;
        }

        $emailAlerts = array_values(array_filter($alerts, fn ($a) => $a['rule']->notify_email));
        if (empty($emailAlerts)) {
            return;
        }

        $this->configureSmtp();

        try {
            Mail::to($recipient)->send(new StreamAlertMail($emailAlerts));
        } catch (\Exception $e) {
            Log::warning("Failed to send alert email: {$e->getMessage()}");
        }
    }

    private function configureSmtp(): void
    {
        $host = Setting::get('smtp_host');
        if (! $host) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => Setting::get('smtp_port', 587),
            'mail.mailers.smtp.username' => Setting::get('smtp_username') ?: null,
            'mail.mailers.smtp.password' => Setting::get('smtp_password') ?: null,
            'mail.mailers.smtp.encryption' => Setting::get('smtp_encryption') ?: null,
            'mail.from.address' => Setting::get('mail_from_address'),
            'mail.from.name' => Setting::get('mail_from_name', 'StreamRadar'),
        ]);
    }
}
