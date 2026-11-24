<?php

namespace App\Services;

use App\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * The notification template of an alert rule (version 2):
 *
 *     version     2
 *     message     blocks, or null for the built-in message
 *     click       'stream' (open the stream), 'none' or 'custom'
 *     click_url   blocks of the custom link, null unless click is 'custom'
 *     attachment  'preview', 'avatar', 'box_art' or 'none'
 *     actions     up to three ntfy buttons: [{label: blocks, url: blocks}]
 *
 * A block is literal text ({type: text, value}) or a field of the stream
 * ({type: field, field}). Version 1 templates ({version: 1, blocks}) are
 * still accepted and come back as version 2. A template that changes
 * nothing comes back as null.
 */
class AlertTemplateValidator
{
    /** The fields a template can use, with the label and description the token picker shows. */
    public const FIELDS = [
        'streamer_name' => ['label' => 'Streamer name', 'description' => 'Display name of the channel'],
        'streamer_login' => ['label' => 'Streamer login', 'description' => 'Login name, as used in Twitch links'],
        'title' => ['label' => 'Title', 'description' => 'Stream title'],
        'category' => ['label' => 'Category', 'description' => 'Game or category being streamed'],
        'viewers' => ['label' => 'Viewers', 'description' => 'Current viewer count'],
        'language' => ['label' => 'Language', 'description' => 'Language code of the stream'],
        'url' => ['label' => 'Twitch URL', 'description' => 'Link to the channel on Twitch'],
        'alert_names' => ['label' => 'Alert name', 'description' => 'Alert rules that matched'],
        'tags' => ['label' => 'Tags', 'description' => 'Stream tags, comma separated'],
        'started_at' => ['label' => 'Start time', 'description' => 'When the stream started (ISO 8601)'],
    ];

    /** The built-in message, also used for rules without a custom one. */
    public const DEFAULT_MESSAGE = [
        ['type' => 'field', 'field' => 'title'],
        ['type' => 'text', 'value' => "\n"],
        ['type' => 'field', 'field' => 'category'],
        ['type' => 'text', 'value' => ' · '],
        ['type' => 'field', 'field' => 'viewers'],
        ['type' => 'text', 'value' => " viewers\nTags: "],
        ['type' => 'field', 'field' => 'tags'],
        ['type' => 'text', 'value' => "\nAlert: "],
        ['type' => 'field', 'field' => 'alert_names'],
    ];

    public const CLICK_ACTIONS = ['stream', 'none', 'custom'];

    public const ATTACHMENTS = ['preview', 'avatar', 'box_art', 'none'];

    /** ntfy shows at most three action buttons. */
    public const MAX_ACTIONS = 3;

    private const KEYS = ['version', 'message', 'click', 'click_url', 'attachment', 'actions'];

    /** Limits per kind of text: blocks, characters of literal text, whether line breaks are allowed. */
    private const LIMITS = [
        'message' => [50, 2000, true],
        'url' => [20, 500, false],
        'label' => [10, 100, false],
    ];

    /** @return array<string, array{label: string, description: string}> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * The normalized template, or null when it changes nothing.
     *
     * @throws ValidationException
     */
    public function validate(?array $template): ?array
    {
        if ($template === null || $template === []) {
            return null;
        }

        $version = $template['version'] ?? null;

        if ($version === 1) {
            if (! isset($template['blocks']) || ! is_array($template['blocks']) || array_diff(array_keys($template), ['version', 'blocks'])) {
                $this->fail('notification_template', 'The alert template is invalid.');
            }
            $template = ['version' => 2, 'message' => $template['blocks']];
        } elseif ($version !== 2 || array_diff(array_keys($template), self::KEYS)) {
            $this->fail('notification_template', 'The alert template is invalid.');
        }

        $message = $this->blocks($template['message'] ?? null, 'message', 'notification_template.message');
        if ($message === [] || $message === self::DEFAULT_MESSAGE) {
            $message = null;
        }

        $click = $template['click'] ?? 'stream';
        if (! in_array($click, self::CLICK_ACTIONS, true)) {
            $this->fail('notification_template.click', 'Choose what happens when the notification is clicked.');
        }

        $clickUrl = null;
        if ($click === 'custom') {
            $clickUrl = $this->link($template['click_url'] ?? null, 'notification_template.click_url');
        }

        $attachment = $template['attachment'] ?? 'preview';
        if (! in_array($attachment, self::ATTACHMENTS, true)) {
            $this->fail('notification_template.attachment', 'This attachment is not supported.');
        }

        $actions = $this->actions($template['actions'] ?? []);

        if ($message === null && $click === 'stream' && $attachment === 'preview' && $actions === []) {
            return null;
        }

        return [
            'version' => 2,
            'message' => $message,
            'click' => $click,
            'click_url' => $clickUrl,
            'attachment' => $attachment,
            'actions' => $actions,
        ];
    }

    /**
     * Whether $value is an absolute http(s) link that is safe to put into a
     * header or an embed: no whitespace or control characters.
     */
    public static function isLink(string $value): bool
    {
        return strlen($value) <= 2048
            && preg_match('#^https?://#i', $value) === 1
            && ! preg_match('/[\s\x00-\x1F\x7F"<>\\\\]/', $value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && (string) parse_url($value, PHP_URL_HOST) !== '';
    }

    /** @return array<int, array{label: array, url: array}> */
    private function actions(mixed $actions): array
    {
        if ($actions === null) {
            return [];
        }

        if (! is_array($actions) || ! array_is_list($actions)) {
            $this->fail('notification_template.actions', 'The action buttons are invalid.');
        }

        if (count($actions) > self::MAX_ACTIONS) {
            $this->fail('notification_template.actions', 'A notification can have at most '.self::MAX_ACTIONS.' action buttons.');
        }

        $result = [];
        foreach ($actions as $i => $action) {
            if (! is_array($action) || array_diff(array_keys($action), ['label', 'url'])) {
                $this->fail("notification_template.actions.{$i}", 'This action button is invalid.');
            }

            $label = $this->blocks($action['label'] ?? null, 'label', "notification_template.actions.{$i}.label");
            if (! $this->hasContent($label)) {
                $this->fail("notification_template.actions.{$i}.label", 'Give the button a label.');
            }

            $result[] = ['label' => $label, 'url' => $this->link($action['url'] ?? null, "notification_template.actions.{$i}.url")];
        }

        return $result;
    }

    /**
     * Blocks of a link: not empty, and a valid http(s) link once filled in.
     * Field values are URL-encoded when the link is built (except the
     * Twitch URL), so a sample value stands in for each of them here.
     */
    private function link(mixed $blocks, string $key): array
    {
        $blocks = $this->blocks($blocks, 'url', $key);

        $sample = '';
        foreach ($blocks as $block) {
            $sample .= $block['type'] === 'text' ? $block['value'] : ($block['field'] === 'url' ? 'https://www.twitch.tv/sample' : 'sample');
        }

        if (trim($sample) === '') {
            $this->fail($key, 'Enter a link.');
        }

        if (! self::isLink($sample)) {
            $this->fail($key, 'The link must start with http:// or https:// and contain no spaces.');
        }

        return $blocks;
    }

    /**
     * Validated blocks with adjacent text merged and empty text dropped.
     *
     * @param  'message'|'url'|'label'  $kind
     */
    private function blocks(mixed $blocks, string $kind, string $key): array
    {
        if ($blocks === null) {
            return [];
        }

        [$maxBlocks, $maxChars, $multiline] = self::LIMITS[$kind];

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->fail($key, 'The alert template is invalid.');
        }

        $result = [];
        $chars = 0;
        foreach ($blocks as $i => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if ($type === 'field') {
                if (! is_string($block['field'] ?? null) || ! array_key_exists($block['field'], self::FIELDS)) {
                    $this->fail("{$key}.{$i}.field", 'This alert field is not supported.');
                }
                $result[] = ['type' => 'field', 'field' => $block['field']];

                continue;
            }

            if ($type !== 'text') {
                $this->fail("{$key}.{$i}", 'Each block must be text or field.');
            }

            // An empty string arrives as null (ConvertEmptyStringsToNull).
            $value = $block['value'] ?? '';
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                $this->fail("{$key}.{$i}.value", 'Custom text is invalid.');
            }

            if ($multiline) {
                $value = str_replace(["\r\n", "\r"], "\n", $value);
            }

            if ($multiline ? preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value) : Text::hasControlChars($value)) {
                $this->fail("{$key}.{$i}.value", $multiline ? 'Custom text may not contain control characters.' : 'Line breaks are not allowed here.');
            }

            $chars += mb_strlen($value);
            if ($value === '') {
                continue;
            }

            $last = array_key_last($result);
            if ($last !== null && $result[$last]['type'] === 'text') {
                $result[$last]['value'] .= $value;
            } else {
                $result[] = ['type' => 'text', 'value' => $value];
            }
        }

        if ($chars > $maxChars) {
            $this->fail($key, 'Custom text must be '.number_format($maxChars).' characters or fewer.');
        }

        if (count($result) > $maxBlocks) {
            $this->fail($key, "Use at most {$maxBlocks} tokens and pieces of text.");
        }

        return $result;
    }

    private function hasContent(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block['type'] === 'field' || trim($block['value']) !== '') {
                return true;
            }
        }

        return false;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
