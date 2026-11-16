<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoryEvent extends Model
{
    use BelongsToOwner, HasFactory;
    public $timestamps = false;

    protected $fillable = [
        'type',
        'stream_twitch_id',
        'streamer_login',
        'streamer_name',
        'category_name',
        'title',
        'viewer_count',
        'profile_image_url',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'viewer_count' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HistoryEvent $event) {
            $event->created_at ??= now();
        });
    }

    public function scopeRecent(Builder $query, int $limit = 100): Builder
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
