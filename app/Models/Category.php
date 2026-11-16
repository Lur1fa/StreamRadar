<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToOwner, HasFactory;
    protected $fillable = [
        'twitch_id',
        'name',
        'box_art_url',
        'is_active',
        'notifications_enabled',
        'use_global_filters',
        'filter_source',
        'min_viewers',
        'min_avg_viewers',
        'languages',
        'keywords',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'notifications_enabled' => 'boolean',
            'use_global_filters' => 'boolean',
            'min_viewers' => 'integer',
            'min_avg_viewers' => 'integer',
            'languages' => 'array',
            'keywords' => 'array',
            'tags' => 'array',
        ];
    }

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function effectiveFilters(): array
    {
        $source = $this->filter_source ?? ($this->use_global_filters ? 'global' : 'custom');

        if (str_starts_with($source, 'tag:')) {
            $tag = substr($source, 4);
            $tagFilter = TagFilter::ownedBy((int) $this->owner_id)->where('tag', $tag)->first();
            if ($tagFilter) {
                return [
                    'min_viewers' => $tagFilter->min_viewers,
                    'min_avg_viewers' => $tagFilter->min_avg_viewers,
                    'languages' => $tagFilter->languages ?? [],
                    'keywords' => $tagFilter->keywords ?? [],
                ];
            }
        }

        if ($source === 'custom') {
            return [
                'min_viewers' => $this->min_viewers,
                'min_avg_viewers' => $this->min_avg_viewers,
                'languages' => $this->languages ?? [],
                'keywords' => $this->keywords ?? [],
            ];
        }

        // The account's own default filters
        $defaults = UserSetting::getMany((int) $this->owner_id, ['global_min_viewers', 'global_min_avg_viewers', 'global_languages', 'global_keywords']);

        return [
            'min_viewers' => (int) $defaults['global_min_viewers'] ?: null,
            'min_avg_viewers' => (int) $defaults['global_min_avg_viewers'] ?: null,
            'languages' => $defaults['global_languages'] ? (json_decode($defaults['global_languages'], true) ?: []) : [],
            'keywords' => $defaults['global_keywords'] ? (json_decode($defaults['global_keywords'], true) ?: []) : [],
        ];
    }

    public function getBoxArtUrlSized(int $width = 188, int $height = 250): ?string
    {
        if (! $this->box_art_url) {
            return null;
        }

        return str_replace(['{width}', '{height}'], [$width, $height], $this->box_art_url);
    }
}
