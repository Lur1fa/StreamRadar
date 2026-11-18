<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesAccounts;
use App\Models\User;
use Illuminate\Console\Command;

class UserRoleCommand extends Command
{
    use ManagesAccounts;

    protected $signature = 'user:role {email : E-mail of the account} {role : admin or user}';

    protected $description = 'Change the role of an account';

    public function handle(): int
    {
        $role = strtolower((string) $this->argument('role'));
        if (! in_array($role, User::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', User::ROLES));

            return self::FAILURE;
        }

        $user = $this->accountOrFail((string) $this->argument('email'));
        if (! $user) {
            return self::FAILURE;
        }

        if ($role === User::ROLE_USER && $user->isLastActiveAdmin()) {
            $this->error('Refusing to demote the last administrator.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();
        $this->info("{$user->email} is now: {$role}");

        return self::SUCCESS;
    }
}
