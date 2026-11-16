<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserListCommand extends Command
{
    protected $signature = 'user:list';

    protected $description = 'List user accounts';

    public function handle(): int
    {
        $rows = User::orderBy('name')->get()->map(fn (User $u) => [
            $u->id,
            $u->name,
            $u->email,
            $u->role,
            $u->hasLocalPassword() ? 'yes' : 'no',
            $u->isSsoLinked() ? 'yes' : 'no',
            $u->isDisabled() ? 'disabled' : 'active',
            $u->last_login_at?->toDateTimeString() ?? '—',
        ]);

        if ($rows->isEmpty()) {
            $this->warn('No accounts exist. Run "php artisan auth:setup-token" or "php artisan user:create --admin".');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'E-mail', 'Role', 'Password', 'SSO', 'Status', 'Last login'], $rows->all());

        return self::SUCCESS;
    }
}
