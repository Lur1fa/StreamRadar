<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\Notifications\AlertPresenter;

/**
 * Fills a rule's notification template (see AlertTemplateValidator) with a
 * stream. Every value is escaped for the channel it goes to; in links,
 * field values are URL-encoded, except the Twitch URL itself.
 */
class AlertTemplateRenderer
{
    public function __construct(private AlertTemplateValidator $validator) {}

    /**
     * The message: the template's, or the built-in one.
     *
     * @param  'plain'|'telegram'|'html'  $channel
     */
    public function render(?array $template, Stream $stream, array $entry, string $channel = 'plain'): string
    {
        $template = $this->validator->validate($template);

        return $this->fill($template['message'] ?? AlertTemplateValidator::DEFAULT_MESSAGE, $this->values($stream, $entry), $channel);
    }

    /**
     * Everything besides the message: the link opened on click (null for
     * none), the attachment, and the action buttons whose label is not
     * empty and whose link is valid once filled in.
     *
     * @return array{click: ?string, attachment: string, actions: array<int, array{label: string, url: string}>}
     */
    public function options(?array $template, Stream $stream, array $entry): array
    {
        $template = $this->validator->validate($template);
        $values = $this->values($stream, $entry);

        $click = match ($template['click'] ?? 'stream') {
            'none' => null,
            'custom' => $this->link($template['click_url'] ?? [], $values),
            default => AlertPresenter::url($stream),
        };

        $actions = [];
        foreach ($template['actions'] ?? [] as $action) {
            $label = trim($this->fill($action['label'], $values, 'plain'));
            $url = $this->link($action['url'], $values);

            if ($label !== '' && $url !== null) {
                $actions[] = ['label' => $label, 'url' => $url];
            }
        }

        return ['click' => $click, 'attachment' => $template['attachment'] ?? 'preview', 'actions' => $actions];
    }

    /**
     * The value of every field for a stream, unescaped.
     *
     * @param  array{rules?: string[], rule?: AlertRule}  $entry
     * @return array<string, string>
     */
    public function values(Stream $stream, array $entry): array
    {
        return [
            'streamer_name' => (string) $stream->user_name,
            'streamer_login' => (string) $stream->user_login,
            'title' => (string) $stream->title,
            'category' => AlertPresenter::category($stream),
            'viewers' => number_format((int) $stream->viewer_count),
            'language' => strtoupper($stream->language ?? '?'),
            'url' => AlertPresenter::url($stream),
            'alert_names' => implode(', ', $entry['rules'] ?? [$entry['rule']->name]),
            'tags' => $stream->tags ? implode(', ', $stream->tags) : '',
            'started_at' => $stream->started_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * @param  array<int, array{type: string, value?: string, field?: string}>  $blocks
     * @param  array<string, string>  $values
     * @param  'plain'|'telegram'|'html'|'url'  $channel
     */
    private function fill(array $blocks, array $values, string $channel): string
    {
        $result = '';
        foreach ($blocks as $block) {
            if ($block['type'] === 'text') {
                $result .= $channel === 'url' ? $block['value'] : $this->escape($block['value'], $channel);
            } else {
                $value = $values[$block['field']] ?? '';
                $result .= $channel === 'url'
                    ? ($block['field'] === 'url' ? $value : rawurlencode($value))
                    : $this->escape($value, $channel);
            }
        }

        return $result;
    }

    /** A filled-in link, or null when it is not a valid http(s) link. */
    private function link(array $blocks, array $values): ?string
    {
        $url = trim($this->fill($blocks, $values, 'url'));

        return AlertTemplateValidator::isLink($url) ? $url : null;
    }

    private function escape(string $value, string $channel): string
    {
        return match ($channel) {
            'telegram' => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'html' => e($value),
            default => $value,
        };
    }
}
