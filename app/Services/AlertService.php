<?php

namespace App\Services;

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\NotificationDestination;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Services\Http\SafeHttpClient;
use App\Services\Notifications\DeliveryFailed;
use App\Services\Notifications\DestinationType;
use App\Support\ImageUrl;
use App\Support\Redactor;
use Illuminate\Http\Client\Response;
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
        // Only the rules of the account this stream row belongs to.
        $rules = AlertRule::ownedBy((int) $stream->owner_id)->where('is_active', true)->get();

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

            HistoryEvent::createFor((int) $stream->owner_id, [
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
        $streams = Stream::ownedBy((int) $rule->owner_id)->get();

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
     * Deliver triggered alerts to the destinations of the account each rule
     * belongs to: every enabled destination whose type the rule has switched
     * on. Each account and each destination is isolated: a failure (network
     * error, bad template, misconfiguration) is logged and never propagates
     * to the caller, so a scheduled sync always completes and one account
     * cannot hold up another.
     *
     * @param  array<array{rule: AlertRule, stream: Stream}>  $allTriggered
     */
    public function sendNotifications(array $allTriggered): void
    {
        $byOwner = [];
        foreach ($allTriggered as $alert) {
            $ownerId = $alert['rule']->owner_id;
            if ($ownerId !== null) {
                $byOwner[(int) $ownerId][] = $alert;
            }
        }

        foreach ($byOwner as $ownerId => $alerts) {
            try {
                $destinations = NotificationDestination::ownedBy($ownerId)->where('enabled', true)->orderBy('id')->get();
            } catch (Throwable $e) {
                $this->logFailure('Failed to load alert destinations', $e);

                continue;
            }

            foreach ($destinations as $destination) {
                $flag = $destination->type->ruleFlag();
                $selected = array_values(array_filter($alerts, fn (array $a) => (bool) $a['rule']->getAttribute($flag)));

                if ($selected === [] || ! $destination->isComplete()) {
                    continue;
                }

                try {
                    $this->deliver($destination, $selected);
                } catch (Throwable $e) {
                    $this->logFailure("Failed to send alerts to a {$destination->type->label()} destination", $e, $destination);
                }
            }
        }
    }

    /**
     * Send alerts to one destination, whether or not it is enabled. Every
     * message is attempted; the first failure is rethrown afterwards, so a
     * test from the Settings page can report it.
     *
     * @param  array<array{rule: AlertRule, stream: Stream}>  $alerts
     *
     * @throws Throwable
     */
    public function deliver(NotificationDestination $destination, array $alerts): void
    {
        match ($destination->type) {
            DestinationType::Ntfy => $this->sendNtfy($destination, $alerts),
            DestinationType::Webhook => $this->sendWebhook($destination, $alerts),
            DestinationType::Email => $this->sendEmail($destination, $alerts),
            DestinationType::Telegram => $this->sendTelegram($destination, $alerts),
            DestinationType::Discord => $this->sendDiscord($destination, $alerts),
        };
    }

    /**
     * Run $send for every item; rethrow the first failure once all were tried.
     *
     * @param  array<mixed>  $items
     */
    private function eachMessage(array $items, callable $send): void
    {
        $failure = null;

        foreach ($items as $item) {
            try {
                $send($item);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function ensureSuccessful(Response $response, string $service): void
    {
        if (! $response->successful()) {
            throw DeliveryFailed::fromResponse($service, $response);
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

    private function sendDiscord(NotificationDestination $destination, array $alerts): void
    {
        $webhookUrl = (string) $destination->secret('webhook_url');

        $embeds = [];
        foreach ($this->groupByStream($alerts) as $entry) {
            $p = $this->presentStream($entry);

            $embed = [
                'title' => "{$p['stream']->user_name} is live",
                'url' => $p['url'],
                'color' => 0x9147FF,
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

        $this->eachMessage(array_chunk($embeds, 10), function (array $chunk) use ($webhookUrl) {
            self::ensureSuccessful($this->http->post($webhookUrl, [
                'embeds' => $chunk,
                // Stream titles are attacker-controlled; never let them ping anyone.
                'allowed_mentions' => ['parse' => []],
            ], [], self::discordPolicy()), 'Discord');
        });
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

    private function sendTelegram(NotificationDestination $destination, array $alerts): void
    {
        $token = (string) $destination->secret('bot_token');
        $chatId = (string) ($destination->config['chat_id'] ?? '');

        $apiBase = "https://api.telegram.org/bot{$token}";
        $policy = ['allowed_hosts' => ['api.telegram.org'], 'require_https' => true];

        $this->eachMessage($this->groupByStream($alerts), function (array $entry) use ($apiBase, $chatId, $policy) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $caption = '<b>'.htmlspecialchars("{$s->user_name} is live", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n"
                .$this->render($p['template'], $s, $entry, 'telegram');

            $response = $p['thumbnail']
                ? $this->http->post("{$apiBase}/sendPhoto", [
                    'chat_id' => $chatId,
                    'photo' => $p['thumbnail'],
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ], [], $policy)
                : $this->http->post("{$apiBase}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ], [], $policy);

            self::ensureSuccessful($response, 'Telegram');
        });
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    /**
     * One request per batch with a fixed JSON payload (event
     * `stream.alerts.batch`), using the destination's method, headers,
     * query parameters and authentication.
     */
    private function sendWebhook(NotificationDestination $destination, array $alerts): void
    {
        $config = $destination->config ?? [];

        $payload = [];
        foreach ($this->groupByStream($alerts) as $entry) {
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

        $options = ['query' => $destination->queryParameters()];
        $auth = $config['auth'] ?? 'none';
        if ($auth === 'basic') {
            $options['basic_auth'] = [(string) ($config['auth_username'] ?? ''), (string) $destination->secret('auth_password')];
        } elseif ($auth === 'bearer') {
            $options['bearer_token'] = (string) $destination->secret('auth_token');
        }

        $headers = [];
        foreach ($destination->headers() as $name => $value) {
            $replacesAuth = $auth !== 'none' && strcasecmp($name, 'Authorization') === 0;
            if (! $replacesAuth && ! NotificationDestination::isReservedHeader($name) && ! preg_match('/[\x00-\x1F\x7F]/', $name.$value)) {
                $headers[$name] = $value;
            }
        }

        $response = $this->http->send((string) ($config['method'] ?? 'POST'), (string) $destination->secret('url'), [
            'event' => 'stream.alerts.batch',
            'count' => count($payload),
            'alerts' => $payload,
        ], $headers, $options);

        self::ensureSuccessful($response, 'The webhook endpoint');
    }

    // ── ntfy ─────────────────────────────────────────────────────────────

    /**
     * One plain-text message per stream, with title, click action, preview
     * image and avatar. An access token is sent as a Bearer header, a user
     * name and password as Basic authentication.
     */
    private function sendNtfy(NotificationDestination $destination, array $alerts): void
    {
        $config = $destination->config ?? [];
        $url = rtrim((string) ($config['server_url'] ?? ''), '/').'/'.rawurlencode((string) ($config['topic'] ?? ''));
        $prefix = trim((string) ($config['title_prefix'] ?? ''));

        $options = ['content_type' => 'text/plain'];
        if ($token = $destination->secret('access_token')) {
            $options['bearer_token'] = $token;
        } elseif (trim((string) ($config['username'] ?? '')) !== '') {
            $options['basic_auth'] = [(string) $config['username'], (string) $destination->secret('password')];
        }

        $this->eachMessage($this->groupByStream($alerts), function (array $entry) use ($url, $prefix, $options) {
            $p = $this->presentStream($entry);
            $s = $p['stream'];

            $body = $this->render($p['template'], $s, $entry, 'plain');
            $title = trim($prefix.' '."{$s->user_name} is live");

            $headers = [
                'Title' => '=?UTF-8?B?'.base64_encode($title).'?=',
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

            self::ensureSuccessful($this->http->send('POST', $url, $body, $headers, $options), 'ntfy');
        });
    }

    // ── Email ────────────────────────────────────────────────────────────

    /**
     * The recipients are the account's own; the SMTP server is the
     * installation's, set by an administrator.
     */
    private function sendEmail(NotificationDestination $destination, array $alerts): void
    {
        $this->configureSmtp();

        $prefix = trim((string) ($destination->config['subject_prefix'] ?? ''));

        Mail::to($destination->recipients())->send(new StreamAlertMail($alerts, $prefix !== '' ? $prefix : null));
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

    private function logFailure(string $message, Throwable $e, ?NotificationDestination $destination = null): void
    {
        Log::warning($message, array_filter([
            'destination' => $destination?->id,
            'exception' => $e::class,
            'error' => Redactor::message($e->getMessage()),
        ], fn ($value) => $value !== null));
    }
}
