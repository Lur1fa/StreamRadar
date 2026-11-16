<?php

namespace App\Services;

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Services\Http\SafeHttpClient;
use App\Support\ImageUrl;
use App\Support\Redactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertService
{
    public function __construct(
        private AlertTemplateRenderer $templateRenderer,
        private SafeHttpClient $http,
    ) {}

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
     * Fan out to every enabled channel. Each channel is isolated: a failure
     * (network error, bad template, misconfiguration) is logged and never
     * propagates to the caller, so a scheduled sync always completes.
     *
     * @param  array<array{rule: AlertRule, stream: Stream}>  $allTriggered
     */
    public function sendNotifications(array $allTriggered): void
    {
        if (empty($allTriggered)) {
            return;
        }

        $channels = [
            'email' => fn () => $this->sendEmailAlerts($allTriggered),
            'discord' => fn () => $this->sendDiscordAlerts($allTriggered),
            'telegram' => fn () => $this->sendTelegramAlerts($allTriggered),
            'webhook' => fn () => $this->sendWebhookAlerts($allTriggered),
        ];

        foreach ($channels as $name => $send) {
            if (Setting::get("notifications_{$name}_enabled", '1') === '0') {
                continue;
            }

            try {
                $send();
            } catch (Throwable $e) {
                $this->logFailure("Failed to send {$name} alerts", $e);
            }
        }
    }

    /**
     * @return array<array{stream: Stream, rules: string[], template: ?array}>
     */
    private function groupByStream(array $alerts): array
    {
        $grouped = [];
        foreach ($alerts as $alert) {
            // Keep alerts with different templates separate while retaining the
            // existing batching behavior for rules using the same template.
            $key = $alert['stream']->twitch_id.'|'.md5(json_encode($alert['rule']->notification_template));
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['stream' => $alert['stream'], 'rules' => [], 'template' => $alert['rule']->notification_template];
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
            'avatar' => ImageUrl::safe($stream->profile_image_url),
            'thumbnail' => ImageUrl::safe($stream->getThumbnailUrlSized(440, 248)),
            'category_box_art' => ImageUrl::safe($categoryBoxArt),
            'template' => $entry['template'],
        ];
    }

    /**
     * Render a template, falling back to the built-in format when the stored
     * template is invalid, so one bad rule can never break a channel.
     */
    private function render(?array $template, Stream $stream, array $entry, string $channel): string
    {
        try {
            return $this->templateRenderer->render($template, $stream, $entry, $channel);
        } catch (Throwable $e) {
            Log::warning('Invalid alert notification template; using default format.', [
                'rules' => $entry['rules'] ?? [],
                'error' => Redactor::message($e->getMessage()),
            ]);

            return $this->templateRenderer->render(null, $stream, $entry, $channel);
        }
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
                'title' => "{$p['stream']->user_name} is live",
                'url' => $p['url'],
                'color' => 0x9147ff,
                'author' => [
                    'name' => "{$p['stream']->user_name} is live",
                    'url' => $p['url'],
                ],
                'timestamp' => now()->toIso8601String(),
                'description' => $this->render($p['template'], $p['stream'], $entry, 'discord'),
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
                $this->http->post($webhookUrl, [
                    'embeds' => $chunk,
                    // Stream titles are attacker-controlled; never let them ping anyone.
                    'allowed_mentions' => ['parse' => []],
                ], [], self::discordPolicy());
            } catch (Throwable $e) {
                $this->logFailure('Failed to send Discord alert', $e);
            }
        }
    }

    public static function discordPolicy(): array
    {
        return [
            'allowed_hosts' => (array) config('security.webhook.discord_hosts', []),
            'require_https' => true,
            'path_prefix' => '/api/webhooks/',
        ];
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
        $policy = ['allowed_hosts' => ['api.telegram.org'], 'require_https' => true];

        foreach ($grouped as $entry) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $caption = '<b>'.htmlspecialchars("{$s->user_name} is live", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n"
                .$this->render($p['template'], $s, $entry, 'telegram');

            try {
                if ($p['thumbnail']) {
                    $this->http->post("{$apiBase}/sendPhoto", [
                        'chat_id' => $chatId,
                        'photo' => $p['thumbnail'],
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                    ], [], $policy);
                } else {
                    $this->http->post("{$apiBase}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ], [], $policy);
                }
            } catch (Throwable $e) {
                $this->logFailure('Failed to send Telegram alert', $e);
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

    private function sendJsonWebhook(string $webhookUrl, array $webhookAlerts): void
    {
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
            $this->http->post($webhookUrl, [
                'event' => 'stream.alerts.batch',
                'count' => count($payload),
                'alerts' => $payload,
            ]);
        } catch (Throwable $e) {
            $this->logFailure('Failed to send webhook alert', $e);
        }
    }

    private function sendNtfyWebhook(string $webhookUrl, array $webhookAlerts): void
    {
        foreach ($this->groupByStream($webhookAlerts) as $entry) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $body = $this->render($p['template'], $s, $entry, 'plain');

            $headers = [
                'Title' => '=?UTF-8?B?'.base64_encode("{$s->user_name} is live").'?=',
            ];

            // Never put a value with CR/LF or control characters into a header.
            if ($p['url'] !== '' && ! preg_match('/[\s\x00-\x1F\x7F]/', $p['url'])) {
                $headers['Click'] = $p['url'];
            }
            if ($p['thumbnail']) {
                $headers['Attach'] = $p['thumbnail'].'?t='.time();
                $headers['Filename'] = 'preview.jpg';
            }
            if ($p['avatar']) {
                $headers['Icon'] = $p['avatar'];
            }

            try {
                $this->http->post($webhookUrl, $body, $headers, ['content_type' => 'text/plain']);
            } catch (Throwable $e) {
                $this->logFailure('Failed to send webhook alert', $e);
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
        } catch (Throwable $e) {
            $this->logFailure('Failed to send alert email', $e);
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

    private function logFailure(string $message, Throwable $e): void
    {
        Log::warning($message, [
            'exception' => $e::class,
            'error' => Redactor::message($e->getMessage()),
        ]);
    }
}
