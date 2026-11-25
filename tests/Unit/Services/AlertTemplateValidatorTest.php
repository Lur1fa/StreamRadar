<?php

use App\Services\AlertTemplateValidator;
use Illuminate\Validation\ValidationException;

function tplText(string $value): array
{
    return ['type' => 'text', 'value' => $value];
}

function tplField(string $field): array
{
    return ['type' => 'field', 'field' => $field];
}

function validTemplate(?array $template): ?array
{
    return app(AlertTemplateValidator::class)->validate($template);
}

function templateError(array $template): array
{
    try {
        validTemplate($template);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('The template was accepted.');
}

test('a version 1 template becomes version 2 with the default options', function () {
    expect(validTemplate(['version' => 1, 'blocks' => [tplText('LIVE: '), tplField('title')]]))->toBe([
        'version' => 2,
        'message' => [tplText('LIVE: '), tplField('title')],
        'click' => 'stream',
        'click_url' => null,
        'attachment' => 'preview',
        'actions' => [],
    ]);
});

test('a template that changes nothing is stored as null', function () {
    expect(validTemplate(['version' => 2]))->toBeNull()
        ->and(validTemplate(['version' => 2, 'message' => [], 'click' => 'stream', 'attachment' => 'preview', 'actions' => []]))->toBeNull()
        ->and(validTemplate(['version' => 2, 'message' => AlertTemplateValidator::DEFAULT_MESSAGE]))->toBeNull()
        ->and(validTemplate(['version' => 1, 'blocks' => []]))->toBeNull();
});

test('adjacent text is merged, so the built-in message split differently is still the default', function () {
    $split = [tplField('title'), tplText("\n"), tplField('category'), tplText(' · '), tplField('viewers'), tplText(' viewers'), tplText("\nTags: "), tplField('tags'), tplText("\n"), tplText('Alert: '), tplField('alert_names')];

    expect(validTemplate(['version' => 2, 'message' => $split]))->toBeNull();
});

test('a custom click link, attachment and action buttons are kept', function () {
    $template = validTemplate([
        'version' => 2,
        'click' => 'custom',
        'click_url' => [tplText('https://example.com/?q='), tplField('title')],
        'attachment' => 'box_art',
        'actions' => [['label' => [tplText('Chat with '), tplField('streamer_name')], 'url' => [tplField('url')]]],
    ]);

    expect($template)
        ->message->toBeNull()
        ->click_url->toBe([tplText('https://example.com/?q='), tplField('title')])
        ->attachment->toBe('box_art')
        ->actions->toBe([['label' => [tplText('Chat with '), tplField('streamer_name')], 'url' => [tplField('url')]]]);
});

test('the click link is dropped unless the click action is custom', function () {
    expect(validTemplate(['version' => 2, 'click' => 'none', 'click_url' => [tplText('https://example.com')]]))
        ->click->toBe('none')
        ->click_url->toBeNull();
});

test('invalid templates are rejected', function (array $template, string $key) {
    expect(templateError($template))->toHaveKey($key);
})->with([
    'unknown version' => [['version' => 3], 'notification_template'],
    'unknown key' => [['version' => 2, 'blocks' => 'nope'], 'notification_template'],
    'unknown field' => [['version' => 2, 'message' => [tplField('password')]], 'notification_template.message.0.field'],
    'click link without scheme' => [['version' => 2, 'click' => 'custom', 'click_url' => [tplText('example.com/'), tplField('streamer_login')]], 'notification_template.click_url'],
    'empty click link' => [['version' => 2, 'click' => 'custom', 'click_url' => []], 'notification_template.click_url'],
    'javascript link' => [['version' => 2, 'click' => 'custom', 'click_url' => [tplText('javascript:alert(1)')]], 'notification_template.click_url'],
    'link with spaces' => [['version' => 2, 'click' => 'custom', 'click_url' => [tplText('https://example.com/a b')]], 'notification_template.click_url'],
    'unknown click action' => [['version' => 2, 'click' => 'call'], 'notification_template.click'],
    'unknown attachment' => [['version' => 2, 'attachment' => 'https://example.com/a.png'], 'notification_template.attachment'],
    'too many buttons' => [['version' => 2, 'actions' => array_fill(0, 4, ['label' => [tplText('A')], 'url' => [tplText('https://example.com')]])], 'notification_template.actions'],
    'button without label' => [['version' => 2, 'actions' => [['label' => [tplText('  ')], 'url' => [tplText('https://example.com')]]]], 'notification_template.actions.0.label'],
    'line break in a label' => [['version' => 2, 'actions' => [['label' => [tplText("A\nB")], 'url' => [tplText('https://example.com')]]]], 'notification_template.actions.0.label.0.value'],
    'message too long' => [['version' => 2, 'message' => [tplText(str_repeat('a', 1500)), tplField('title'), tplText(str_repeat('b', 600))]], 'notification_template.message'],
]);

test('line breaks are allowed in the message and Windows line endings are normalized', function () {
    expect(validTemplate(['version' => 2, 'message' => [tplField('title'), tplText("\r\nSecond line")]]))
        ->message->toBe([tplField('title'), tplText("\nSecond line")]);
});
