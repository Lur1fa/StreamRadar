<?php

use App\Models\AlertRule;
use App\Models\BlacklistRule;
use App\Models\Category;
use App\Models\TagFilter;
use App\Models\TrackedChannel;
use App\Services\ConfigImportService;

test('every column a backup exports can be imported again', function () {
    // Validation rules of the import, by section.
    $reflection = new ReflectionMethod(ConfigImportService::class, 'validate');
    $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

    $sections = [
        'categories' => Category::EXPORT_COLUMNS,
        'channels' => TrackedChannel::EXPORT_COLUMNS,
        'blacklist' => BlacklistRule::EXPORT_COLUMNS,
        'tag_filters' => TagFilter::EXPORT_COLUMNS,
    ];
    foreach ($sections as $section => $columns) {
        foreach ($columns as $column) {
            expect($source)->toContain("'{$section}.*.{$column}'");
        }
    }
});

test('an exported backup imports into another account unchanged', function () {
    $admin = adminUser();
    $this->actingAs($admin);
    $category = Category::factory()->create(['tags' => ['fps']]);
    AlertRule::factory()->create(['name' => 'Rule', 'category_ids' => [$category->id], 'notify_ntfy' => true, 'notify_on_stream_start' => false]);

    $backup = $this->getJson('/settings/export')->assertOk()->json();
    expect(array_keys($backup['alerts'][0]))->toContain(...AlertRule::exportColumns());

    $other = adminUser();
    app(ConfigImportService::class)->import($backup, $other);

    $rule = AlertRule::ownedBy($other)->firstOrFail();
    expect($rule->notify_ntfy)->toBeTrue()
        ->and($rule->notify_on_stream_start)->toBeFalse()
        ->and($rule->category_ids)->toBe([Category::ownedBy($other)->value('id')]);
});
