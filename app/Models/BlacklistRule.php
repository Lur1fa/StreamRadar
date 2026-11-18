<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlacklistRule extends Model
{
    use BelongsToOwner, HasFactory;

    public const CHANNEL = 'channel';

    public const KEYWORD = 'keyword';

    public const TAG = 'tag';

    public const TYPES = [self::CHANNEL, self::KEYWORD, self::TAG];

    /** Columns in a configuration backup. */
    public const EXPORT_COLUMNS = ['type', 'value', 'twitch_user_id', 'profile_image_url'];

    protected $fillable = [
        'type',
        'value',
        'twitch_user_id',
        'profile_image_url',
    ];

    public function scopeChannels($query)
    {
        return $query->where('type', self::CHANNEL);
    }

    public function scopeKeywords($query)
    {
        return $query->where('type', self::KEYWORD);
    }

    public function scopeTags($query)
    {
        return $query->where('type', self::TAG);
    }
}
