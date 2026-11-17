<?php

namespace Database\Factories;

use App\Models\AlertRule;
use Database\Factories\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

class AlertRuleFactory extends Factory
{
    use HasOwner;

    protected $model = AlertRule::class;

    public function definition(): array
    {
        return [
            'owner_id' => $this->defaultOwner(),
            'name' => fake()->words(3, true),
            'streamer_login' => null,
            'category_id' => null,
            'category_ids' => null,
            'category_tags' => null,
            'match_mode' => 'always',
            'min_viewers' => null,
            'language' => null,
            'keywords' => null,
            'notify_email' => false,
            'notify_discord' => false,
            'notify_telegram' => false,
            'notify_webhook' => false,
            'notify_ntfy' => false,
            'notify_on_category_change' => false,
            'notify_on_stream_start' => true,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function firstTimeOnly(): static
    {
        return $this->state(['match_mode' => 'first_time']);
    }

    public function forStreamer(string $login): static
    {
        return $this->state(['streamer_login' => $login]);
    }

    public function withEmail(): static
    {
        return $this->state(['notify_email' => true]);
    }

    public function withDiscord(): static
    {
        return $this->state(['notify_discord' => true]);
    }

    public function withTelegram(): static
    {
        return $this->state(['notify_telegram' => true]);
    }

    public function withWebhook(): static
    {
        return $this->state(['notify_webhook' => true]);
    }

    public function withNtfy(): static
    {
        return $this->state(['notify_ntfy' => true]);
    }

    public function withCategoryChange(): static
    {
        return $this->state(['notify_on_category_change' => true]);
    }
}
