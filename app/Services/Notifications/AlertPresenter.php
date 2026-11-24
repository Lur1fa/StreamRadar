<?php

namespace App\Services\Notifications;

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\AlertTemplateRenderer;
use App\Support\ImageUrl;
use App\Support\Redactor;
use App\Support\Twitch;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How a triggered alert looks, the same on every channel (Discord, Telegram,
 * ntfy, webhook, e-mail). Everything that comes from Twitch is untrusted:
 * images are limited to Twitch's CDN and each channel escapes text itself.
 */
class AlertPresenter
{
    public function __construct(private AlertTemplateRenderer $renderer) {}

    /**
     * One entry per stream and template: rules with the same template are
     * sent as one message, rules with different templates separately.
     *
     * @param  array<array{rule: AlertRule, stream: Stream}>  $alerts
     * @return array<array{stream: Stream, rules: string[], template: ?array}>
     */
    public static function group(array $alerts): array
    {
        $grouped = [];
        foreach ($alerts as $alert) {
            $key = $alert['stream']->twitch_id.'|'.md5(json_encode($alert['rule']->notification_template));
            $grouped[$key] ??= ['stream' => $alert['stream'], 'rules' => [], 'template' => $alert['rule']->notification_template];
            $grouped[$key]['rules'][] = $alert['rule']->name;
        }

        return array_values($grouped);
    }

    public static function headline(Stream $stream): string
    {
        return "{$stream->user_name} is live";
    }

    public static function category(Stream $stream): string
    {
        return (string) ($stream->game_name ?? $stream->category?->name ?? 'Unknown');
    }

    /** The channel's Twitch page; Twitch's home page when the login is not a valid Twitch login. */
    public static function url(Stream $stream): string
    {
        return preg_match(Twitch::LOGIN_PATTERN, (string) $stream->user_login)
            ? $stream->getTwitchUrl()
            : 'https://www.twitch.tv/';
    }

    /**
     * The presentation-ready details of a stream. Box art comes in the size
     * the channel shows it at.
     *
     * @return array{url: string, viewers: string, category: string, language: string, avatar: ?string, thumbnail: ?string, category_box_art: ?string}
     */
    public static function details(Stream $stream, int $boxArtWidth, int $boxArtHeight): array
    {
        return [
            'url' => self::url($stream),
            'viewers' => number_format((int) $stream->viewer_count),
            'category' => self::category($stream),
            'language' => strtoupper((string) ($stream->language ?? '?')),
            'avatar' => ImageUrl::safe($stream->profile_image_url),
            'thumbnail' => ImageUrl::safe($stream->getThumbnailUrlSized(440, 248)),
            'category_box_art' => ImageUrl::safe(
                Twitch::sizedImage($stream->game_box_art_url, $boxArtWidth, $boxArtHeight)
                    ?? $stream->category?->getBoxArtUrlSized($boxArtWidth, $boxArtHeight),
            ),
        ];
    }

    /**
     * The message of an entry from group(): the rule's template, or the
     * built-in format when the stored template is invalid, so one bad rule
     * can never break a channel.
     *
     * @param  array{stream: Stream, rules: string[], template: ?array}  $entry
     */
    public function message(array $entry, string $channel): string
    {
        try {
            return $this->renderer->render($entry['template'], $entry['stream'], $entry, $channel);
        } catch (Throwable $e) {
            Log::warning('Invalid alert notification template; using default format.', [
                'rules' => $entry['rules'],
                'error' => Redactor::message($e->getMessage()),
            ]);

            return $this->renderer->render(null, $entry['stream'], $entry, $channel);
        }
    }

    /**
     * Click link, attachment and action buttons of an entry from group();
     * the defaults (open the stream, preview image, no buttons) when the
     * stored template is invalid.
     *
     * @param  array{stream: Stream, rules: string[], template: ?array}  $entry
     * @return array{click: ?string, attachment: string, actions: array<int, array{label: string, url: string}>}
     */
    public function options(array $entry): array
    {
        try {
            return $this->renderer->options($entry['template'], $entry['stream'], $entry);
        } catch (Throwable) {
            // message() already logs an invalid template.
            return $this->renderer->options(null, $entry['stream'], $entry);
        }
    }

    /**
     * The image a rule attaches, from details(): the stream preview, the
     * streamer's avatar, the category's box art, or none.
     *
     * @param  array{avatar: ?string, thumbnail: ?string, category_box_art: ?string}  $details
     */
    public static function attachment(array $details, string $attachment): ?string
    {
        return match ($attachment) {
            'avatar' => $details['avatar'],
            'box_art' => $details['category_box_art'],
            'none' => null,
            default => $details['thumbnail'],
        };
    }
}
