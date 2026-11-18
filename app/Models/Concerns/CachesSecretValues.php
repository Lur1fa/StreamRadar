<?php

namespace App\Models\Concerns;

/**
 * Decrypted secrets of every account, cached for the log redactor (see
 * App\Support\Redactor). Each model using this trait has its own cache:
 * static properties of a trait belong to the using class.
 */
trait CachesSecretValues
{
    /** @var string[]|null */
    private static ?array $secretValues = null;

    /**
     * Every decrypted secret value this model stores, for all accounts.
     *
     * @return iterable<mixed>
     */
    abstract protected static function collectSecretValues(): iterable;

    /**
     * @return string[]
     */
    public static function secretValues(): array
    {
        if (self::$secretValues === null) {
            $values = [];
            foreach (static::collectSecretValues() as $value) {
                if (is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }
            self::$secretValues = array_values(array_unique($values));
        }

        return self::$secretValues;
    }

    public static function flushSecretCache(): void
    {
        self::$secretValues = null;
    }
}
