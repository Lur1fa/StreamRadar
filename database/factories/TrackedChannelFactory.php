<?php

namespace Database\Factories;

use App\Models\TrackedChannel;
use Database\Factories\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrackedChannelFactory extends Factory
{
    use HasOwner;

    protected $model = TrackedChannel::class;

    public function definition(): array
    {
        $login = fake()->unique()->userName();

        return [
            'owner_id' => $this->defaultOwner(),
            'twitch_user_id' => (string) fake()->unique()->numberBetween(10000, 999999),
            'user_login' => strtolower($login),
            'user_name' => $login,
            'profile_image_url' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
