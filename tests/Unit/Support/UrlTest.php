<?php

use App\Support\Url;

test('query parameters are appended with the right separator', function () {
    expect(Url::withQuery('https://idp.example.com/auth', ['scope' => 'openid profile', 'state' => 'a&b']))
        ->toBe('https://idp.example.com/auth?scope=openid%20profile&state=a%26b');
    expect(Url::withQuery('https://idp.example.com/auth?tenant=x', ['a' => '1']))
        ->toBe('https://idp.example.com/auth?tenant=x&a=1');
});

test('no parameters leave the URL untouched', function () {
    expect(Url::withQuery('https://example.com/hook', []))->toBe('https://example.com/hook');
});
