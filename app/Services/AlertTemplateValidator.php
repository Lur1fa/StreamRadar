<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AlertTemplateValidator
{
    public const FIELDS = [
        'streamer_name', 'streamer_login', 'title', 'category', 'viewers',
        'language', 'url', 'alert_names', 'tags', 'started_at',
    ];

    public function validate(?array $template): ?array
    {
        if ($template === null || $template === []) {
            return null;
        }

        if (($template['version'] ?? null) !== 1 || ! isset($template['blocks']) || ! is_array($template['blocks'])) {
            throw ValidationException::withMessages(['notification_template' => 'The alert template is invalid.']);
        }

        if (count($template['blocks']) > 50) {
            throw ValidationException::withMessages(['notification_template' => 'An alert template may contain at most 50 blocks.']);
        }

        foreach ($template['blocks'] as $index => $block) {
            if (! is_array($block) || ! in_array($block['type'] ?? null, ['text', 'field'], true)) {
                throw ValidationException::withMessages(["notification_template.blocks.{$index}" => 'Each block must be text or field.']);
            }

            if ($block['type'] === 'field' && ! in_array($block['field'] ?? null, self::FIELDS, true)) {
                throw ValidationException::withMessages(["notification_template.blocks.{$index}.field" => 'This alert field is not supported.']);
            }

            if ($block['type'] === 'text' && (! is_string($block['value'] ?? null) || mb_strlen($block['value']) > 1000)) {
                throw ValidationException::withMessages(["notification_template.blocks.{$index}.value" => 'Custom text must be 1,000 characters or fewer.']);
            }
        }

        return ['version' => 1, 'blocks' => array_values($template['blocks'])];
    }
}
