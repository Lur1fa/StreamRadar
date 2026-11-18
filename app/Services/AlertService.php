<?php

namespace App\Services;

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\HistoryEvent;
use App\Models\NotificationDestination;
use App\Models\Stream;
use App\Models\StreamAlertTracking;
use App\Services\Http\SafeHttpClient;
use App\Services\Mail\SmtpSettings;
use App\Services\Notifications\AlertPresenter;
use App\Services\Notifications\DeliveryFailed;
use App\Services\Notifications\DestinationType;
use App\Support\Brand;
use App\Support\Redactor;
use App\Support\Text;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AlertService
{
    public function __construct(
        private AlertPresenter $presenter,
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

            self::track($rule, $stream, triggered: ! $silent);

            if (! $silent) {
                $triggeredAlerts[] = ['rule' => $rule, 'stream' => $stream];
            }
        }

        if (! empty($triggeredAlerts)) {
            HistoryEvent::createFor((int) $stream->owner_id, [
                'type' => HistoryEvent::ALERT_TRIGGERED,
                ...HistoryEvent::streamColumns($stream, $stream->game_name),
                'metadata' => [
                    'rule_names' => array_map(fn ($a) => $a['rule']->name, $triggeredAlerts),
                    'rule_ids' => array_map(fn ($a) => $a['rule']->id, $triggeredAlerts),
                ],
            ]);
        }

        return $triggeredAlerts;
    }

    public function seedTrackingForRule(AlertRule $rule): void
    {
        foreach (Stream::ownedBy((int) $rule->owner_id)->get() as $stream) {
            if ($rule->matchesStream($stream)) {
                self::track($rule, $stream, triggered: false);
            }
        }
    }

    /**
     * Remember that a rule has seen a stream, so it does not alert for it
     * again. $triggered also stamps the time an alert went out.
     */
    private static function track(AlertRule $rule, Stream $stream, bool $triggered): void
    {
        StreamAlertTracking::updateOrCreate(
            ['alert_rule_id' => $rule->id, 'stream_twitch_id' => $stream->twitch_id],
            ['streamer_login' => $stream->user_login, ...($triggered ? ['triggered_at' => now()] : [])],
        );
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

    // ── Discord ──────────────────────────────────────────────────────────

    private function sendDiscord(NotificationDestination $destination, array $alerts): void
    {
        $webhookUrl = (string) $destination->secret('webhook_url');

        $embeds = [];
        foreach (AlertPresenter::group($alerts) as $entry) {
            $p = AlertPresenter::details($entry['stream'], 188, 250);
            $headline = AlertPresenter::headline($entry['stream']);

            $embed = [
                'title' => $headline,
                'url' => $p['url'],
                'color' => Brand::colorInt(),
                'author' => ['name' => $headline, 'url' => $p['url']],
                'timestamp' => now()->toIso8601String(),
                'description' => $this->presenter->message($entry, 'discord'),
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

    /**
     * Where a Discord webhook may point. Validation of a saved URL uses the
     * same policy (SafeWebhookUrl::discord()).
     *
     * @return array{allowed_hosts: string[], require_https: bool, path_prefix: string}
     */
    public static function discordPolicy(): array
    {
        return [
            'allowed_hosts' => (array) config('security.webhook.discord.hosts'),
            'require_https' => true,
            'path_prefix' => (string) config('security.webhook.discord.path_prefix'),
        ];
    }

    // ── Telegram ─────────────────────────────────────────────────────────

    private function sendTelegram(NotificationDestination $destination, array $alerts): void
    {
        $token = (string) $destination->secret('bot_token');
        $chatId = (string) ($destination->config['chat_id'] ?? '');

        $apiBase = "https://api.telegram.org/bot{$token}";
        $policy = ['allowed_hosts' => ['api.telegram.org'], 'require_https' => true];

        $this->eachMessage(AlertPresenter::group($alerts), function (array $entry) use ($apiBase, $chatId, $policy) {
            $thumbnail = AlertPresenter::details($entry['stream'], 188, 250)['thumbnail'];

            $caption = '<b>'.htmlspecialchars(AlertPresenter::headline($entry['stream']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n"
                .$this->presenter->message($entry, 'telegram');

            $response = $thumbnail
                ? $this->http->post("{$apiBase}/sendPhoto", [
                    'chat_id' => $chatId,
                    'photo' => $thumbnail,
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
        foreach (AlertPresenter::group($alerts) as $entry) {
            $s = $entry['stream'];
            $p = AlertPresenter::details($s, 188, 250);

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
            if (! $replacesAuth && ! NotificationDestination::isReservedHeader($name) && ! Text::hasControlChars($name.$value)) {
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

        $this->eachMessage(AlertPresenter::group($alerts), function (array $entry) use ($url, $prefix, $options) {
            $p = AlertPresenter::details($entry['stream'], 188, 250);
            $title = trim($prefix.' '.AlertPresenter::headline($entry['stream']));

            $headers = [
                'Title' => '=?UTF-8?B?'.base64_encode($title).'?=',
            ];

            // Never put a value with whitespace or control characters into a header.
            if ($p['url'] !== '' && ! preg_match('/\s/', $p['url']) && ! Text::hasControlChars($p['url'])) {
                $headers['Click'] = $p['url'];
            }
            if ($p['thumbnail']) {
                $headers['Attach'] = $p['thumbnail'].'?t='.time();
                $headers['Filename'] = 'preview.jpg';
            }
            if ($p['avatar']) {
                $headers['Icon'] = $p['avatar'];
            }

            self::ensureSuccessful($this->http->send('POST', $url, $this->presenter->message($entry, 'plain'), $headers, $options), 'ntfy');
        });
    }

    // ── Email ────────────────────────────────────────────────────────────

    /**
     * The recipients are the account's own; the SMTP server is the
     * installation's, set by an administrator.
     */
    private function sendEmail(NotificationDestination $destination, array $alerts): void
    {
        SmtpSettings::apply();

        $prefix = trim((string) ($destination->config['subject_prefix'] ?? ''));

        Mail::to($destination->recipients())->send(new StreamAlertMail($alerts, $prefix !== '' ? $prefix : null));
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
