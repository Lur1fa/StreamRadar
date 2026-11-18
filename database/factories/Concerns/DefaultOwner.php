<?php

namespace Database\Factories\Concerns;

use App\Models\User;

/**
 * The account test data belongs to unless a test says otherwise: the
 * signed-in user, else the first account, else a new one. Used by the
 * factories (HasOwner) and by testOwner() in tests/Pest.php.
 */
final class DefaultOwner
{
    public static function id(): int
    {
        return auth()->id()
            ?? User::query()->orderBy('id')->value('id')
            ?? User::factory()->create()->id;
    }
}
