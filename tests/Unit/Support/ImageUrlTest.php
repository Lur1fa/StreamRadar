<?php

use App\Support\ImageUrl;

test('https Twitch CDN URLs are allowed', function () {
    expect(ImageUrl::safe('https://static-cdn.jtvnw.net/previews/live.jpg'))
        ->toBe('https://static-cdn.jtvnw.net/previews/live.jpg');
    expect(ImageUrl::safe('https://clips-media-assets2.twitch.tv/x.jpg'))->not->toBeNull();
});

test('non-Twitch, non-https and dangerous URLs are dropped', function () {
    expect(ImageUrl::safe('https://evil.example.com/x.jpg'))->toBeNull();
    expect(ImageUrl::safe('http://static-cdn.jtvnw.net/x.jpg'))->toBeNull();
    expect(ImageUrl::safe('javascript:alert(1)'))->toBeNull();
    expect(ImageUrl::safe('data:image/png;base64,AAAA'))->toBeNull();
    expect(ImageUrl::safe('https://static-cdn.jtvnw.net.evil.com/x.jpg'))->toBeNull();
    expect(ImageUrl::safe(null))->toBeNull();
    expect(ImageUrl::safe("https://static-cdn.jtvnw.net/\n.jpg"))->toBeNull();
});
