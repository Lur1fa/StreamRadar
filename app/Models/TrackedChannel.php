<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackedChannel extends Model
{
    use BelongsToOwner, HasFactory;

    /** Columns in a configuration backup. */
    public const EXPORT_COLUMNS = ['twitch_user_id', 'user_login', 'user_name', 'profile_image_url', 'is_active'];

    protected $fillable = [
        'twitch_user_id',
        'user_login',
        'user_name',
        'profile_image_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
