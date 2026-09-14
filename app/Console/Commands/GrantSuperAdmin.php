<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantSuperAdmin extends Command
{
    protected $signature = 'admin:grant
                            {email : The user to promote}
                            {--revoke : Take the flag away instead}';

    protected $description = 'Grant (or revoke) super admin access to the admin panel';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user with email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        $user->forceFill(['is_super_admin' => $grant])->save();

        $this->info($grant
            ? "{$user->email} is now a super admin. The Admin link appears in their user menu."
            : "{$user->email} is no longer a super admin.");

        return self::SUCCESS;
    }
}
