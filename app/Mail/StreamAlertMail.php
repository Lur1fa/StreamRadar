<?php

namespace App\Mail;

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\AlertTemplateRenderer;
use App\Support\ImageUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alert e-mail. Everything that originates from Twitch (titles, display
 * names, category names, image URLs) or from user input (rule names,
 * templates) is treated as untrusted: the Blade view escapes all text with
 * {{ }} and only https images on Twitch's CDN are embedded.
 */
class StreamAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<array{rule: AlertRule, stream: Stream}> $alerts */
    public function __construct(
        public array $alerts,
    ) {}

    public function envelope(): Envelope
    {
        $grouped = $this->groupByStream();
        $count = count($grouped);

        if ($count === 1) {
            $stream = $grouped[0]['stream'];
            $ruleNames = implode(', ', $grouped[0]['rules']);
            $subject = "[StreamRadar] {$stream->user_name} is live — {$ruleNames}";
        } else {
            $names = array_slice(array_map(fn ($g) => $g['stream']->user_name, $grouped), 0, 3);
            $label = implode(', ', $names).($count > 3 ? ' +'.($count - 3).' more' : '');
            $subject = "[StreamRadar] {$count} streams are live — {$label}";
        }

        return new Envelope(subject: self::sanitizeHeader($subject));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.stream-alert',
            with: ['entries' => $this->presentEntries()],
        );
    }

    /**
     * @return array<array{stream: Stream, rules: string[], template: ?array}>
     */
    private function groupByStream(): array
    {
        $grouped = [];
        foreach ($this->alerts as $alert) {
            $key = $alert['stream']->twitch_id.'|'.md5(json_encode($alert['rule']->notification_template));
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['stream' => $alert['stream'], 'rules' => [], 'template' => $alert['rule']->notification_template];
            }
            $grouped[$key]['rules'][] = $alert['rule']->name;
        }

        return array_values($grouped);
    }

    /**
     * Plain data for the view. Nothing here is HTML; the view escapes it.
     */
    private function presentEntries(): array
    {
        $renderer = app(AlertTemplateRenderer::class);

        return array_map(function (array $entry) use ($renderer) {
            $stream = $entry['stream'];

            $categoryBoxArt = $stream->game_box_art_url
                ? str_replace(['{width}', '{height}'], ['36', '48'], $stream->game_box_art_url)
                : $stream->category?->getBoxArtUrlSized(36, 48);

            try {
                $message = $renderer->render($entry['template'], $stream, $entry, 'plain');
            } catch (Throwable) {
                $message = $renderer->render(null, $stream, $entry, 'plain');
            }

            return [
                'user_name' => (string) $stream->user_name,
                'title' => (string) $stream->title,
                'rules' => implode(', ', $entry['rules']),
                'url' => preg_match('/^[a-z0-9_]{1,25}$/i', (string) $stream->user_login) ? $stream->getTwitchUrl() : 'https://www.twitch.tv/',
                'viewers' => number_format((int) $stream->viewer_count),
                'category' => (string) ($stream->game_name ?? $stream->category?->name ?? 'Unknown'),
                'language' => strtoupper((string) ($stream->language ?? '?')),
                'avatar' => ImageUrl::safe($stream->profile_image_url),
                'thumbnail' => ImageUrl::safe($stream->getThumbnailUrlSized(440, 248)),
                'category_box_art' => ImageUrl::safe($categoryBoxArt),
                'message' => $message,
            ];
        }, $this->groupByStream());
    }

    /** Strip CR/LF and other control characters so a title can never inject headers. */
    public static function sanitizeHeader(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return Str::limit(trim($clean), 200, '…');
    }
}
