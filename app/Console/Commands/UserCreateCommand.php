<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserCreateCommand extends Command
{
    protected $signature = 'user:create
                            {--admin : Give the account the administrator role}
                            {--name= : Display name}
                            {--email= : Login e-mail address}
                            {--password= : Password (omit to be prompted securely)}
                            {--password-stdin : Read the password from standard input (for scripts)}';

    protected $description = 'Create a local user account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Display name');
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail address (used to sign in)'))));

        if ($this->option('password-stdin')) {
            $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
        } elseif ($this->option('password') !== null) {
            $password = (string) $this->option('password');
        } else {
            $password = (string) $this->secret('Password (min. '.config('security.password_min_length', 12).' characters)');
            $confirm = (string) $this->secret('Confirm password');
            if (! hash_equals($password, $confirm)) {
                $this->error('Passwords do not match.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::min((int) config('security.password_min_length', 12))],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $this->option('admin') ? User::ROLE_ADMIN : User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        $this->info("Created {$user->role} account for {$user->email}.");

        return self::SUCCESS;
    }
}
