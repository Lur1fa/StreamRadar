<?php

use App\Models\AlertRule;
use App\Models\Stream;
use App\Services\Notifications\AlertPresenter;

test('rules with the same template are grouped per stream, different templates stay apart', function () {
    $stream = new Stream(['twitch_id' => '1', 'user_login' => 'a', 'user_name' => 'A']);
    $template = ['version' => 1, 'blocks' => [['type' => 'field', 'field' => 'title']]];

    $groups = AlertPresenter::group([
        ['rule' => new AlertRule(['name' => 'One']), 'stream' => $stream],
        ['rule' => new AlertRule(['name' => 'Two']), 'stream' => $stream],
        ['rule' => new AlertRule(['name' => 'Three', 'notification_template' => $template]), 'stream' => $stream],
    ]);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['rules'])->toBe(['One', 'Two'])
        ->and($groups[1]['rules'])->toBe(['Three'])
        ->and($groups[1]['template'])->toBe($template);
});

test('details are the same for every channel and only link valid Twitch logins', function () {
    $stream = new Stream([
        'user_login' => 'shroud', 'user_name' => 'shroud', 'viewer_count' => 42831, 'language' => 'en',
        'game_name' => 'VALORANT', 'game_box_art_url' => 'https://static-cdn.jtvnw.net/ttv-boxart/1_IGDB-{width}x{height}.jpg',
        'profile_image_url' => 'https://evil.example.com/a.png',
    ]);

    $details = AlertPresenter::details($stream, 36, 48);

    expect($details)
        ->url->toBe('https://www.twitch.tv/shroud')
        ->viewers->toBe('42,831')
        ->category->toBe('VALORANT')
        ->language->toBe('EN')
        ->avatar->toBeNull()
        ->category_box_art->toBe('https://static-cdn.jtvnw.net/ttv-boxart/1_IGDB-36x48.jpg')
        ->and(AlertPresenter::headline($stream))->toBe('shroud is live')
        ->and(AlertPresenter::url(new Stream(['user_login' => '../admin'])))->toBe('https://www.twitch.tv/');
});
