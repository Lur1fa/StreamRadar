<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserRoleCommand extends Command
{
    protected $signature = 'user:role {email : E-mail of the account} {role : admin or user}';

    protected $description = 'Change the role of an account';

    public function handle(): int
    {
        $role = strtolower((string) $this->argument('role'));
        if (! in_array($role, User::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', User::ROLES));

            return self::FAILURE;
        }

        $user = User::findByEmail($this->argument('email'));
        if (! $user) {
            $this->error('No account with that e-mail address.');

            return self::FAILURE;
        }

        if ($role === User::ROLE_USER && $user->isAdmin() && User::admins()->active()->where('id', '!=', $user->id)->doesntExist()) {
            $this->error('Refusing to demote the last administrator.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();
        $this->info("{$user->email} is now: {$role}");

        return self::SUCCESS;
    }
}
