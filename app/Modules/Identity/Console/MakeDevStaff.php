<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Support\DevMode;
use Illuminate\Console\Command;

/**
 * Decision D-14, development only: turns an existing account into staff so the admin
 * panel can be tried locally and in the browser tests. Staff still need MFA to open
 * the panel. Refuses to run unless development mode is on (APP_ENV=local).
 */
final class MakeDevStaff extends Command
{
    protected $signature = 'fanfolio:dev-staff {email : An existing account} {--role=admin : admin or moderator}';

    protected $description = 'Development only: make an existing account staff (admin or moderator).';

    public function handle(AuditLog $audit): int
    {
        if (! DevMode::enabled()) {
            $this->error('Development mode is off. This command only runs with APP_ENV=local and FANFOLIO_DEV_MODE=true.');

            return self::FAILURE;
        }

        $role = Role::tryFrom((string) $this->option('role'));

        if (! $role instanceof Role || ! $role->isStaff()) {
            $this->error('Use --role=admin or --role=moderator.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->error('No account with that email. Sign up first.');

            return self::FAILURE;
        }

        $from = $user->role;
        $user->forceFill(['role' => $role])->save();
        $audit->record('identity.role_changed', null, 'user', $user->id, ['from' => $from->value, 'to' => $role->value, 'dev_mode' => true]);

        $this->info("{$user->email} is now {$role->value}. Set up a passkey or authenticator app to open /admin.");

        return self::SUCCESS;
    }
}
