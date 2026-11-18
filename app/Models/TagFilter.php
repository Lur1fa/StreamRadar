<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TagFilter extends Model
{
    use BelongsToOwner, HasFactory;

    /** Columns in a configuration backup. */
    public const EXPORT_COLUMNS = ['tag', 'min_viewers', 'min_avg_viewers', 'languages', 'keywords'];

    protected $fillable = [
        'tag',
        'min_viewers',
        'min_avg_viewers',
        'languages',
        'keywords',
    ];

    protected function casts(): array
    {
        return [
            'min_viewers' => 'integer',
            'min_avg_viewers' => 'integer',
            'languages' => 'array',
            'keywords' => 'array',
        ];
    }
}
