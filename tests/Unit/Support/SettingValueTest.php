<?php

use App\Support\SettingValue;

test('values are encoded as the settings tables store them', function () {
    expect(SettingValue::encode(null))->toBeNull();
    expect(SettingValue::encode(true))->toBe('1');
    expect(SettingValue::encode(false))->toBe('0');
    expect(SettingValue::encode(587))->toBe('587');
    expect(SettingValue::encode('mail.example.com'))->toBe('mail.example.com');
});

test('boolean-ish input becomes 1 or 0', function () {
    foreach ([true, '1', 'true', 'on', 'yes'] as $on) {
        expect(SettingValue::flag($on))->toBe('1');
    }
    foreach ([false, '0', 'false', 'off', '', null] as $off) {
        expect(SettingValue::flag($off))->toBe('0');
    }
});

test('stored JSON lists decode to arrays, anything else to an empty list', function () {
    expect(SettingValue::decodeList('["en","de"]'))->toBe(['en', 'de']);
    expect(SettingValue::decodeList('[]'))->toBe([]);
    expect(SettingValue::decodeList(null))->toBe([]);
    expect(SettingValue::decodeList(''))->toBe([]);
    expect(SettingValue::decodeList('not json'))->toBe([]);
    // A JSON scalar is not a list (json_decode alone would return the string).
    expect(SettingValue::decodeList('"en"'))->toBe([]);
});
