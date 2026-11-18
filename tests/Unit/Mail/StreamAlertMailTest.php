<?php

use App\Mail\StreamAlertMail;
use App\Models\AlertRule;
use App\Models\Stream;

test('subject mentions single stream name', function () {
    $stream = Stream::factory()->create(['user_name' => 'TestStreamer']);
    $rule = AlertRule::factory()->create();

    $mail = new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]);
    $envelope = $mail->envelope();

    expect($envelope->subject)->toContain('TestStreamer');
});

test('subject mentions multiple streams', function () {
    $stream1 = Stream::factory()->create(['user_name' => 'Streamer1']);
    $stream2 = Stream::factory()->create(['user_name' => 'Streamer2']);
    $rule = AlertRule::factory()->create();

    $mail = new StreamAlertMail([
        ['rule' => $rule, 'stream' => $stream1],
        ['rule' => $rule, 'stream' => $stream2],
    ]);
    $envelope = $mail->envelope();

    expect($envelope->subject)->toContain('2');
});

test('HTML content includes stream user name', function () {
    $stream = Stream::factory()->create(['user_name' => 'CoolStreamer']);
    $rule = AlertRule::factory()->create();

    $mail = new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]);
    $html = $mail->render();

    expect($html)->toContain('CoolStreamer');
});

test('HTML content includes Twitch URL', function () {
    $stream = Stream::factory()->create(['user_login' => 'coolstreamer']);
    $rule = AlertRule::factory()->create();

    $mail = new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]);
    $html = $mail->render();

    expect($html)->toContain('https://www.twitch.tv/coolstreamer');
});

test('HTML content includes viewer count', function () {
    $stream = Stream::factory()->withViewers(12345)->create();
    $rule = AlertRule::factory()->create();

    $mail = new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]);
    $html = $mail->render();

    expect($html)->toContain('12,345');
});

test('HTML content includes category name', function () {
    $stream = Stream::factory()->create(['game_name' => 'Fortnite']);
    $rule = AlertRule::factory()->create();

    $mail = new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]);
    $html = $mail->render();

    expect($html)->toContain('Fortnite');
});

test('malicious stream titles and names are HTML-escaped in the e-mail', function () {
    $stream = Stream::factory()->create([
        'user_name' => '<script>alert(1)</script>Evil',
        'title' => '<img src=x onerror=alert(1)> watch now',
        'game_name' => '"><b>pwn</b>',
    ]);
    $rule = AlertRule::factory()->create();

    $html = (new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<img src=x onerror=alert(1)>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('&lt;img src=x');
});

test('a non-Twitch avatar URL is not embedded in the e-mail', function () {
    $stream = Stream::factory()->create(['profile_image_url' => 'https://evil.example.com/track.gif']);
    $rule = AlertRule::factory()->create();

    $html = (new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]))->render();

    expect($html)->not->toContain('evil.example.com');
});

test('a header-injecting subject is neutralised', function () {
    $stream = Stream::factory()->create(['user_name' => "Bad\r\nBcc: victim@example.com"]);
    $rule = AlertRule::factory()->create();

    $subject = (new StreamAlertMail([['rule' => $rule, 'stream' => $stream]]))->envelope()->subject;

    expect($subject)->not->toContain("\r")->not->toContain("\n");
});
