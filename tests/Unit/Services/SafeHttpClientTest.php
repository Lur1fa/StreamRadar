<?php

use App\Services\Http\OutboundUrlGuard;
use App\Services\Http\SafeHttpClient;
use Illuminate\Http\Client\ConnectionException;

/*
 * Http::fake() never reaches cURL, so the notification tests cannot see the
 * transport options. These tests go through the real HTTP client.
 */

test('a real request is accepted by the HTTP client and reaches the network', function () {
    // Guzzle 8 rejects raw cURL options outside its allow-list before sending
    // anything (InvalidArgumentException), which broke every notification.
    $client = new SafeHttpClient(new OutboundUrlGuard(fn () => [], ['127.0.0.1']));

    try {
        $client->post('http://127.0.0.1:9/', 'ping');
    } catch (ConnectionException) {
        // Nothing listens there: the request got as far as connecting.
    }

    expect(true)->toBeTrue();
});

test('responses are pinned to the vetted address and capped in size', function () {
    config(['security.webhook.max_response_bytes' => 4096]);
    $guard = new OutboundUrlGuard(fn () => ['93.184.216.34']);
    $options = (new SafeHttpClient($guard))->pendingRequest($guard->validate('https://hooks.example.com/in'))->getOptions();

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:93.184.216.34'])
        ->and($options['protocols'])->toBe(['http', 'https'])
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['progress'](0, 4096, 0, 0))->toBeFalse()
        ->and($options['progress'](0, 4097, 0, 0))->toBeTrue();
});
