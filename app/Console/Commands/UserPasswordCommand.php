<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserPasswordCommand extends Command
{
    protected $signature = 'user:password
                            {email : E-mail of the account}
                            {--password= : New password (omit to be prompted securely)}
                            {--password-stdin : Read the new password from standard input}';

    protected $description = 'Set a new password for a local account (recovery path that never needs the identity provider)';

    public function handle(): int
    {
        $user = User::findByEmail($this->argument('email'));
        if (! $user) {
            $this->error('No account with that e-mail address.');

            return self::FAILURE;
        }

        if ($this->option('password-stdin')) {
            $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
        } elseif ($this->option('password') !== null) {
            $password = (string) $this->option('password');
        } else {
            $password = (string) $this->secret('New password');
            if (! hash_equals($password, (string) $this->secret('Confirm new password'))) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::min((int) config('security.password_min_length', 12))],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password, 'disabled_at' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->info("Password updated for {$user->email}. All existing sessions were signed out.");

        return self::SUCCESS;
    }
}
