<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoryEvent extends Model
{
    use BelongsToOwner, HasFactory;

    public const STREAM_ONLINE = 'stream_online';

    public const STREAM_OFFLINE = 'stream_offline';

    public const ALERT_TRIGGERED = 'alert_triggered';

    public const SYNC_COMPLETED = 'sync_completed';

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

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The columns that describe a stream, for stream_online, stream_offline
     * and alert_triggered events.
     *
     * @return array<string, mixed>
     */
    public static function streamColumns(Stream $stream, ?string $categoryName = null): array
    {
        return [
            'stream_twitch_id' => $stream->twitch_id,
            'streamer_login' => $stream->user_login,
            'streamer_name' => $stream->user_name,
            'category_name' => $categoryName ?? $stream->category?->name,
            'title' => $stream->title,
            'viewer_count' => $stream->viewer_count,
            'profile_image_url' => $stream->profile_image_url,
        ];
    }
}
