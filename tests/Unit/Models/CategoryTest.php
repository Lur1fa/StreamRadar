<?php

use App\Models\Category;
use App\Models\Stream;

test('effectiveFilters returns local filters when use_global_filters is false', function () {
    $category = Category::factory()->withLocalFilters(200, ['pl', 'en'], ['gaming'])->create();

    $filters = $category->effectiveFilters();

    expect($filters['min_viewers'])->toBe(200)
        ->and($filters['languages'])->toBe(['pl', 'en'])
        ->and($filters['keywords'])->toBe(['gaming']);
});

test('effectiveFilters returns global settings when use_global_filters is true', function () {
    setAccountSetting('global_min_viewers', '100');
    setAccountSetting('global_languages', json_encode(['de']));
    setAccountSetting('global_keywords', json_encode(['esports']));

    $category = Category::factory()->create(['use_global_filters' => true]);
    $filters = $category->effectiveFilters();

    expect($filters['min_viewers'])->toBe(100)
        ->and($filters['languages'])->toBe(['de'])
        ->and($filters['keywords'])->toBe(['esports']);
});

test('effectiveFilters returns empty arrays when global settings are not configured', function () {
    $category = Category::factory()->create(['use_global_filters' => true]);
    $filters = $category->effectiveFilters();

    expect($filters['min_viewers'])->toBeNull()
        ->and($filters['languages'])->toBe([])
        ->and($filters['keywords'])->toBe([]);
});

test('getBoxArtUrlSized replaces width and height placeholders', function () {
    $category = Category::factory()->create([
        'box_art_url' => 'https://example.com/art-{width}x{height}.jpg',
    ]);

    expect($category->getBoxArtUrlSized(100, 200))->toBe('https://example.com/art-100x200.jpg');
});

test('getBoxArtUrlSized returns null when box_art_url is null', function () {
    $category = Category::factory()->create(['box_art_url' => null]);

    expect($category->getBoxArtUrlSized())->toBeNull();
});

test('getBoxArtUrlSized uses default dimensions', function () {
    $category = Category::factory()->create([
        'box_art_url' => 'https://example.com/{width}x{height}.jpg',
    ]);

    expect($category->getBoxArtUrlSized())->toBe('https://example.com/188x250.jpg');
});

test('has many streams', function () {
    $category = Category::factory()->create();
    Stream::factory()->count(3)->forCategory($category)->create();

    expect($category->streams)->toHaveCount(3);
});

test('has many alert rules', function () {
    $category = Category::factory()->create();
    \App\Models\AlertRule::factory()->count(2)->create(['category_id' => $category->id]);

    expect($category->alertRules)->toHaveCount(2);
});
