<?php

namespace App\Services;

use App\Models\Stream;

class AlertTemplateRenderer
{
    public function __construct(private AlertTemplateValidator $validator) {}

    public function render(?array $template, Stream $stream, array $entry, string $channel = 'plain'): string
    {
        $template = $this->validator->validate($template);
        $category = $stream->game_name ?? $stream->category?->name ?? 'Unknown';
        $tags = $stream->tags ? implode(', ', $stream->tags) : '';
        $rules = implode(', ', $entry['rules'] ?? [$entry['rule']->name]);

        if (! $template) {
            $values = [
                'title' => $stream->title,
                'category' => $category,
                'viewers' => number_format($stream->viewer_count),
                'tags' => $tags,
                'alert_names' => $rules,
            ];

            $result = $values['title']."\n"
                .$values['category']." · {$values['viewers']} viewers\n"
                .'Tags: '.$values['tags']."\n"
                .'Alert: '.$values['alert_names'];

            return $this->escape($result, $channel);
        }

        $values = [
            'streamer_name' => $stream->user_name,
            'streamer_login' => $stream->user_login,
            'title' => $stream->title,
            'category' => $category,
            'viewers' => number_format($stream->viewer_count),
            'language' => strtoupper($stream->language ?? '?'),
            'url' => $stream->getTwitchUrl(),
            'alert_names' => $rules,
            'tags' => $tags,
            'started_at' => $stream->started_at?->toIso8601String() ?? '',
        ];

        $result = '';
        foreach ($template['blocks'] as $block) {
            $value = $block['type'] === 'text' ? $block['value'] : ($values[$block['field']] ?? '');
            $result .= $this->escape((string) $value, $channel);
        }

        return $result;
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
