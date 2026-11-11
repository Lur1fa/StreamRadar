<?php

namespace App\Mail;

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\AlertTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
            $label = implode(', ', $names).($count > 3 ? " +".($count - 3)." more" : '');
            $subject = "[StreamRadar] {$count} streams are live — {$label}";
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

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

    private function buildHtml(): string
    {
        $grouped = $this->groupByStream();
        $count = count($grouped);

        $html = <<<'HTML'
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; background: #f9fafb; padding: 24px;">
            <div style="text-align: center; margin-bottom: 24px;">
                <div style="display: inline-block; background: linear-gradient(135deg, #9147ff, #6366f1); padding: 10px 16px; border-radius: 12px;">
                    <span style="color: white; font-size: 18px; font-weight: bold;">StreamRadar</span>
                </div>
            </div>
        HTML;

        if ($count > 1) {
            $html .= "<p style=\"text-align: center; color: #6b7280; font-size: 14px; margin-bottom: 20px;\">{$count} streams matched your alert rules</p>";
        }

        foreach ($grouped as $entry) {
            $stream = $entry['stream'];
            $ruleNames = implode(', ', $entry['rules']);
            $url = $stream->getTwitchUrl();
            $viewers = number_format($stream->viewer_count);
            $category = $stream->game_name ?? $stream->category?->name ?? 'Unknown';
            $avatar = $stream->profile_image_url;
            $lang = strtoupper($stream->language ?? '?');
            $thumbnail = $stream->getThumbnailUrlSized(440, 248);
            $categoryBoxArt = $stream->game_box_art_url
                ? str_replace(['{width}', '{height}'], ['36', '48'], $stream->game_box_art_url)
                : $stream->category?->getBoxArtUrlSized(36, 48);

            $avatarHtml = $avatar
                ? "<img src=\"{$avatar}\" width=\"48\" height=\"48\" style=\"border-radius: 50%; margin-right: 12px; vertical-align: middle;\" alt=\"{$stream->user_name}\" />"
                : '<div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.2); margin-right: 12px; display: inline-block; vertical-align: middle;"></div>';

            $categoryHtml = $categoryBoxArt
                ? "<img src=\"{$categoryBoxArt}\" width=\"16\" height=\"21\" style=\"vertical-align: middle; margin-right: 4px; border-radius: 2px;\" />{$category}"
                : $category;

            $thumbnailHtml = $thumbnail
                ? "<a href=\"{$url}\" style=\"display: block; margin-bottom: 12px;\"><img src=\"{$thumbnail}\" width=\"100%\" style=\"border-radius: 8px; display: block;\" alt=\"Stream preview\" /></a>"
                : '';
            $message = app(AlertTemplateRenderer::class)->render($entry['template'], $stream, $entry, 'html');
            $customMessageHtml = '<div style="white-space: pre-wrap; margin: 0 0 12px; color: #374151; font-size: 14px;">'.$message.'</div>';

            $html .= <<<HTML
            <div style="background: white; border-radius: 12px; overflow: hidden; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                <div style="background: linear-gradient(135deg, #9147ff, #6366f1); padding: 12px 16px;">
                    <table cellpadding="0" cellspacing="0" border="0"><tr>
                        <td>{$avatarHtml}</td>
                        <td>
                            <div style="color: white; font-weight: 700; font-size: 16px;">{$stream->user_name}</div>
                            <div style="color: rgba(255,255,255,0.8); font-size: 12px;">is live</div>
                        </td>
                    </tr></table>
                </div>
                <div style="padding: 16px;">
                    <p style="margin: 0 0 12px; font-size: 15px; font-weight: 600; color: #111827;">{$stream->title}</p>
                    {$thumbnailHtml}
                    {$customMessageHtml}
                    <table cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 12px;">
                        <tr>
                            <td style="padding-right: 16px;">
                                <span style="color: #9147ff; font-weight: 600; font-size: 13px;">{$categoryHtml}</span>
                            </td>
                            <td style="padding-right: 16px;">
                                <span style="color: #ef4444; font-weight: 600; font-size: 13px;">👁 {$viewers}</span>
                            </td>
                            <td>
                                <span style="color: #6b7280; font-size: 13px;">🌐 {$lang}</span>
                            </td>
                        </tr>
                    </table>
                    <a href="{$url}" style="display: inline-block; padding: 10px 24px; background: #9147ff; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">Watch on Twitch →</a>
                    <p style="margin: 12px 0 0; color: #9ca3af; font-size: 11px;">Alert: {$ruleNames}</p>
                </div>
            </div>
            HTML;
        }

        $html .= <<<'HTML'
            <p style="text-align: center; color: #9ca3af; font-size: 11px; margin-top: 8px;">
                Sent by StreamRadar · Manage alerts in Settings
            </p>
        </div>
        HTML;

        return $html;
    }
}
