<?php

use App\Models\Setting;

test('get returns stored value', function () {
    Setting::set('theme', 'dark');

    expect(Setting::get('theme'))->toBe('dark');
});

test('get returns default when key does not exist', function () {
    expect(Setting::get('nonexistent', 'fallback'))->toBe('fallback');
});

test('set creates new setting', function () {
    Setting::set('new_key', 'new_value');

    $this->assertDatabaseHas('settings', ['key' => 'new_key', 'value' => 'new_value']);
});

test('set updates existing setting', function () {
    Setting::set('key', 'original');
    Setting::set('key', 'updated');

    expect(Setting::get('key'))->toBe('updated');
    $this->assertDatabaseCount('settings', 1);
});

test('getMany returns multiple values with defaults', function () {
    Setting::set('existing', 'yes');

    $result = Setting::getMany(['existing', 'missing'], ['missing' => 'default']);

    expect($result['existing'])->toBe('yes')
        ->and($result['missing'])->toBe('default');
});
