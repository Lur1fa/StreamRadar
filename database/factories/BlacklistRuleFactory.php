<?php

namespace Database\Factories;

use App\Models\BlacklistRule;
use Database\Factories\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlacklistRuleFactory extends Factory
{
    use HasOwner;

    protected $model = BlacklistRule::class;

    public function definition(): array
    {
        return [
            'owner_id' => $this->defaultOwner(),
            'type' => 'channel',
            'value' => strtolower(fake()->unique()->userName()),
            'twitch_user_id' => null,
            'profile_image_url' => null,
        ];
    }

    public function channel(?string $login = null): static
    {
        return $this->state(fn () => [
            'type' => 'channel',
            'value' => $login ?? strtolower(fake()->unique()->userName()),
        ]);
    }

    public function keyword(?string $keyword = null): static
    {
        return $this->state(fn () => [
            'type' => 'keyword',
            'value' => $keyword ?? fake()->unique()->word(),
        ]);
    }

    public function tag(?string $tag = null): static
    {
        return $this->state(fn () => [
            'type' => 'tag',
            'value' => $tag ?? fake()->unique()->word(),
        ]);
    }
}
