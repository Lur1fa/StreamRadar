<?php

namespace Database\Factories;

use App\Models\HistoryEvent;
use Database\Factories\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class HistoryEventFactory extends Factory
{
    use HasOwner;

    protected $model = HistoryEvent::class;

    public function definition(): array
    {
        return [
            'owner_id' => $this->defaultOwner(),
            'type' => fake()->randomElement(['stream_online', 'stream_offline', 'alert_triggered']),
            'stream_twitch_id' => (string) fake()->numberBetween(100000, 9999999),
            'streamer_login' => fake()->userName(),
            'streamer_name' => fake()->userName(),
            'category_name' => fake()->words(2, true),
            'title' => fake()->sentence(),
            'viewer_count' => fake()->numberBetween(10, 50000),
            'profile_image_url' => null,
            'metadata' => null,
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function syncCompleted(): static
    {
        return $this->state([
            'type' => 'sync_completed',
            'stream_twitch_id' => null,
            'streamer_login' => null,
            'streamer_name' => null,
            'metadata' => ['new' => 5, 'updated' => 10, 'ended' => 2],
        ]);
    }
}
