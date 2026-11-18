<?php

use App\Models\Category;
use App\Models\Stream;
use App\Support\Twitch;

test('channel URLs point at the login', function () {
    expect(Twitch::channelUrl('shroud'))->toBe('https://www.twitch.tv/shroud');
    expect(Twitch::channelUrl('a/b?c'))->toBe('https://www.twitch.tv/a%2Fb%3Fc');
});

test('image templates are filled, missing templates stay null', function () {
    expect(Twitch::sizedImage('https://static-cdn.jtvnw.net/x-{width}x{height}.jpg', 440, 248))
        ->toBe('https://static-cdn.jtvnw.net/x-440x248.jpg');
    expect(Twitch::sizedImage(null, 1, 1))->toBeNull();
    expect(Twitch::sizedImage('', 1, 1))->toBeNull();
});

test('sized images match the model helpers they replace', function () {
    $stream = new Stream(['user_login' => 'shroud', 'thumbnail_url' => 'https://static-cdn.jtvnw.net/live_{width}x{height}.jpg']);
    $category = new Category(['box_art_url' => 'https://static-cdn.jtvnw.net/ttv-boxart/1-{width}x{height}.jpg']);

    expect(Twitch::sizedImage($stream->thumbnail_url, 440, 248))->toBe($stream->getThumbnailUrlSized(440, 248));
    expect(Twitch::sizedImage($category->box_art_url, 188, 250))->toBe($category->getBoxArtUrlSized(188, 250));
    expect(Twitch::channelUrl($stream->user_login))->toBe($stream->getTwitchUrl());
});

test('box art template is built from the game id', function () {
    expect(Twitch::boxArtTemplate('509658'))->toBe('https://static-cdn.jtvnw.net/ttv-boxart/509658_IGDB-{width}x{height}.jpg');
    expect(Twitch::boxArtTemplate(null))->toBeNull();
    expect(Twitch::boxArtTemplate(''))->toBeNull();
});

test('offline thumbnails are recognised', function () {
    expect(Twitch::isOfflineThumbnail('https://static-cdn.jtvnw.net/ttv-static/404_preview-440x248.jpg'))->toBeTrue();
    expect(Twitch::isOfflineThumbnail('https://static-cdn.jtvnw.net/previews-ttv/live_user_x.jpg'))->toBeFalse();
    expect(Twitch::isOfflineThumbnail(null))->toBeFalse();
    expect(Twitch::isOfflineThumbnail(''))->toBeFalse();
});
