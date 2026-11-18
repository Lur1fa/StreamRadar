<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;

/**
 * Shared by the user:* commands.
 */
trait ManagesAccounts
{
    /** The account with this e-mail address, or null after printing an error. */
    protected function accountOrFail(string $email): ?User
    {
        $user = User::findByEmail($email);

        if (! $user) {
            $this->error('No account with that e-mail address.');
        }

        return $user;
    }

    /**
     * A new password from --password-stdin, --password, or a confirmed
     * prompt. Null after printing an error when the two prompts differ.
     */
    protected function newPassword(string $prompt, string $confirmPrompt): ?string
    {
        if ($this->option('password-stdin')) {
            return rtrim((string) stream_get_contents(STDIN), "\r\n");
        }

        if ($this->option('password') !== null) {
            return (string) $this->option('password');
        }

        $password = (string) $this->secret($prompt);
        if (! hash_equals($password, (string) $this->secret($confirmPrompt))) {
            $this->error('Passwords do not match.');

            return null;
        }

        return $password;
    }
}
