<?php

use App\Support\Text;

test('control characters are detected', function () {
    expect(Text::hasControlChars("X-Header\r\nInjected: 1"))->toBeTrue();
    expect(Text::hasControlChars("tab\there"))->toBeTrue();
    expect(Text::hasControlChars("del\x7F"))->toBeTrue();
    expect(Text::hasControlChars('Ünïcödé — fine'))->toBeFalse();
});

test('control character runs become one space', function () {
    expect(Text::stripControlChars("  line one\r\n\tline two\x00 "))->toBe('line one line two');
    expect(Text::stripControlChars('Ünïcödé — fine'))->toBe('Ünïcödé — fine');
    // Invalid UTF-8 survives instead of collapsing to an empty string.
    expect(Text::stripControlChars("ok\xC3\x28\n"))->toBe("ok\xC3\x28");
});

test('the printable rule rejects control characters', function () {
    $pattern = substr(Text::PRINTABLE_RULE, strlen('regex:'));

    expect(preg_match($pattern, 'My ntfy · alerts'))->toBe(1);
    expect(preg_match($pattern, "evil\r\nname"))->toBe(0);
});

test('containsAny matches any needle, ignoring case', function () {
    expect(Text::containsAny('VALORANT Ranked Grind', ['ranked', 'casual']))->toBeTrue();
    expect(Text::containsAny('VALORANT Ranked Grind', ['casual']))->toBeFalse();
    expect(Text::containsAny('anything', []))->toBeFalse();
    // Same as the loops it replaces: an empty keyword matches.
    expect(Text::containsAny('anything', ['']))->toBeTrue();
});
