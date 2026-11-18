<?php

namespace App\Support;

use Inertia\DevTools\EntriesRepository;

/**
 * Wraps the Inertia DevTools entry store so that every recorded request is
 * scrubbed by key *pattern* and by known secret *values* before it touches
 * disk. The package's own redaction only matches an exact list of key names.
 */
class RedactingEntriesRepository extends EntriesRepository
{
    public function save(string $id, array $data): void
    {
        parent::save($id, Redactor::array($data));
    }
}
