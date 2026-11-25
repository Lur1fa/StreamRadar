<?php

namespace App\Mail;

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\Notifications\AlertPresenter;
use App\Support\Text;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Alert e-mail. Everything that originates from Twitch (titles, display
 * names, category names, image URLs) or from user input (rule names,
 * templates) is treated as untrusted: the Blade view escapes all text with
 * {{ }} and only https images on Twitch's CDN are embedded.
 */
class StreamAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<array{rule: AlertRule, stream: Stream}>  $alerts
     * @param  string|null  $subjectPrefix  Replaces "[StreamRadar]" at the start of the subject.
     */
    public function __construct(
        public array $alerts,
        public ?string $subjectPrefix = null,
    ) {}

    public function envelope(): Envelope
    {
        $grouped = AlertPresenter::group($this->alerts);
        $count = count($grouped);
        $prefix = trim((string) $this->subjectPrefix) !== '' ? trim((string) $this->subjectPrefix) : '[StreamRadar]';

        if ($count === 1) {
            $ruleNames = implode(', ', $grouped[0]['rules']);
            $subject = "{$prefix} ".AlertPresenter::headline($grouped[0]['stream'])." — {$ruleNames}";
        } else {
            $names = array_slice(array_map(fn ($g) => $g['stream']->user_name, $grouped), 0, 3);
            $label = implode(', ', $names).($count > 3 ? ' +'.($count - 3).' more' : '');
            $subject = "{$prefix} {$count} streams are live — {$label}";
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
     * Plain data for the view. Nothing here is HTML; the view escapes it.
     */
    private function presentEntries(): array
    {
        $presenter = app(AlertPresenter::class);

        return array_map(function (array $entry) use ($presenter) {
            $attachment = $presenter->options($entry)['attachment'];

            return [
                ...AlertPresenter::details($entry['stream'], 36, 48),
                'user_name' => (string) $entry['stream']->user_name,
                'title' => (string) $entry['stream']->title,
                'rules' => implode(', ', $entry['rules']),
                'message' => $presenter->message($entry, 'plain'),
                // The big image: full width for the stream preview, smaller for an avatar or box art.
                'image' => AlertPresenter::attachment(AlertPresenter::details($entry['stream'], 188, 250), $attachment),
                'image_width' => $attachment === 'preview' ? '100%' : '160',
            ];
        }, AlertPresenter::group($this->alerts));
    }

    /** Strip CR/LF and other control characters so a title can never inject headers. */
    public static function sanitizeHeader(string $value): string
    {
        return Str::limit(Text::stripControlChars($value), 200, '…');
    }
}
