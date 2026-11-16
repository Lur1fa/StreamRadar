<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stream extends Model
{
    use BelongsToOwner, HasFactory;
    protected $fillable = [
        'twitch_id',
        'user_id',
        'user_login',
        'user_name',
        'category_id',
        'game_name',
        'game_box_art_url',
        'title',
        'viewer_count',
        'avg_viewers',
        'language',
        'thumbnail_url',
        'profile_image_url',
        'started_at',
        'tags',
        'is_mature',
        'synced_at',
        'missing_since',
    ];

    protected function casts(): array
    {
        return [
            'viewer_count' => 'integer',
            'avg_viewers' => 'integer',
            'started_at' => 'datetime',
            'synced_at' => 'datetime',
            'missing_since' => 'datetime',
            'tags' => 'array',
            'is_mature' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeForActiveCategories(Builder $query): Builder
    {
        return $query->whereHas('category', fn (Builder $q) => $q->where('is_active', true));
    }

    public function getThumbnailUrlSized(int $width = 440, int $height = 248): ?string
    {
        if (! $this->thumbnail_url) {
            return null;
        }

        return str_replace(['{width}', '{height}'], [$width, $height], $this->thumbnail_url);
    }

    public function getTwitchUrl(): string
    {
        return "https://www.twitch.tv/{$this->user_login}";
    }
}
