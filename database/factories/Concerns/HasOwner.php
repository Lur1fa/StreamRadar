<?php

namespace Database\Factories\Concerns;

use Closure;

/**
 * Account-owned models need an owner. Unless a test says otherwise
 * (`->for($user, 'owner')` or `['owner_id' => …]`), that is the signed-in
 * user, else the first account, else a new one; so everything one test
 * creates belongs to the same account by default.
 */
trait HasOwner
{
    protected function defaultOwner(): Closure
    {
        return fn () => DefaultOwner::id();
    }
}
