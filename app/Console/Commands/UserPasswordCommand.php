<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserPasswordCommand extends Command
{
    use ManagesAccounts;

    protected $signature = 'user:password
                            {email : E-mail of the account}
                            {--password= : New password (omit to be prompted securely)}
                            {--password-stdin : Read the new password from standard input}';

    protected $description = 'Set a new password for a local account (recovery path that never needs the identity provider)';

    public function handle(): int
    {
        $user = $this->accountOrFail((string) $this->argument('email'));
        if (! $user) {
            return self::FAILURE;
        }

        $password = $this->newPassword('New password', 'Confirm new password');
        if ($password === null) {
            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::defaults()],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password, 'disabled_at' => null])->save();
        $user->endAllSessions();

        $this->info("Password updated for {$user->email}. All existing sessions were signed out.");

        return self::SUCCESS;
    }
}
