<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Stream;
use Database\Factories\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreamFactory extends Factory
{
    use HasOwner;

    protected $model = Stream::class;

    public function definition(): array
    {
        return [
            'owner_id' => $this->defaultOwner(),
            'twitch_id' => (string) fake()->unique()->numberBetween(100000, 9999999),
            'user_id' => (string) fake()->numberBetween(10000, 999999),
            'user_login' => fake()->unique()->userName(),
            'user_name' => fake()->userName(),
            'category_id' => Category::factory(),
            'game_name' => fake()->words(2, true),
            'game_box_art_url' => null,
            'title' => fake()->sentence(),
            'viewer_count' => fake()->numberBetween(10, 50000),
            'language' => fake()->randomElement(['en', 'pl', 'de', 'es', 'fr']),
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/previews-ttv/live_user_{width}x{height}.jpg',
            'profile_image_url' => 'https://example.com/avatar.jpg',
            'started_at' => now()->subMinutes(fake()->numberBetween(5, 300)),
            'tags' => ['English', 'Gaming'],
            'is_mature' => false,
            'synced_at' => now(),
        ];
    }

    public function mature(): static
    {
        return $this->state(['is_mature' => true]);
    }

    public function withViewers(int $count): static
    {
        return $this->state(['viewer_count' => $count]);
    }

    public function forCategory(Category $category): static
    {
        return $this->state([
            'category_id' => $category->id,
            'game_name' => $category->name,
        ]);
    }
}
