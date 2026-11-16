<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Data that belongs to exactly one account (tracking, alerts, blacklist,
 * history, streams).
 *
 * Isolation rests on three things, all in this trait:
 *  - a record cannot be created without an owner;
 *  - `owner_id` is not mass assignable, so request data can never set it;
 *  - route model binding only resolves records of the signed-in account, so
 *    another account's record is a 404, never a 403 that confirms it exists.
 *
 * Queries are scoped explicitly with ownedBy(); there is deliberately no
 * global scope, because the scheduler works across all accounts.
 *
 * @property int|null $owner_id
 */
trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void
    {
        static::creating(function (Model $model) {
            if ($model->getAttribute('owner_id') === null) {
                throw new LogicException(static::class.' must be created for an account (owner_id is missing).');
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeOwnedBy(Builder $query, User|int $owner): Builder
    {
        return $query->where($query->qualifyColumn('owner_id'), $owner instanceof User ? $owner->getKey() : $owner);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createFor(User|int $owner, array $attributes): static
    {
        $model = new static($attributes);
        $model->setAttribute('owner_id', $owner instanceof User ? $owner->getKey() : $owner);
        $model->save();

        return $model;
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    public static function updateOrCreateFor(User|int $owner, array $match, array $values = []): static
    {
        $model = static::query()->ownedBy($owner)->where($match)->first();

        if ($model) {
            $model->fill($values)->save();

            return $model;
        }

        return static::createFor($owner, array_merge($match, $values));
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('owner_id', $userId)
            ->first();
    }
}
