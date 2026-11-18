<?php

namespace Database\Factories;

use App\Models\Category;
use Database\Factories\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    use HasOwner;

    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'owner_id' => $this->defaultOwner(),
            'twitch_id' => (string) fake()->unique()->numberBetween(10000, 999999),
            'name' => fake()->words(2, true),
            'box_art_url' => 'https://static-cdn.jtvnw.net/ttv-boxart/{width}x{height}.jpg',
            'is_active' => true,
            'notifications_enabled' => true,
            'use_global_filters' => true,
            'min_viewers' => null,
            'languages' => null,
            'keywords' => null,
            'tags' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withLocalFilters(int $minViewers = 100, array $languages = ['en'], array $keywords = []): static
    {
        return $this->state([
            'use_global_filters' => false,
            'min_viewers' => $minViewers,
            'languages' => $languages,
            'keywords' => $keywords,
        ]);
    }
}
