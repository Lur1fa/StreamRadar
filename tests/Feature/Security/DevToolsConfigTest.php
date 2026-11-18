<?php

use App\Providers\AppServiceProvider;
use App\Support\RedactingEntriesRepository;
use Inertia\DevTools\EntriesRepository;

test('Inertia DevTools are disabled unless explicitly enabled', function () {
    expect((bool) config('inertia.devtools.enabled'))->toBeFalse();
});

test('the DevTools entry endpoints require authentication and the admin gate', function () {
    expect(config('inertia.devtools.middleware'))->toContain('auth')->toContain('can:admin');
    expect(config('inertia.devtools.gate'))->toBe('admin');
});

test('the DevTools routes are not registered when disabled', function () {
    $this->get('/_inertia/devtools/entries')->assertNotFound();
});

test('the DevTools entry store is replaced with a redacting one', function () {
    expect(app(EntriesRepository::class))->toBeInstanceOf(RedactingEntriesRepository::class);
});

test('production forces debug and DevTools off even if the environment asks for them', function () {
    config(['app.debug' => true, 'inertia.devtools.enabled' => true]);
    app()['env'] = 'production';

    (new AppServiceProvider(app()))->boot();

    expect(config('app.debug'))->toBeFalse()
        ->and(config('inertia.devtools.enabled'))->toBeFalse();
});
