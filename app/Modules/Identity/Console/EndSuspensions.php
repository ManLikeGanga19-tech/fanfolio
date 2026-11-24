<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;

/**
 * Plan M8a use case 16: a suspension lifts itself when it ends. Sign-in already allows
 * the account from that moment (User::canSignIn); this sets the status back and audits it.
 */
final class EndSuspensions extends Command
{
    protected $signature = 'identity:end-suspensions';

    protected $description = 'Set accounts whose suspension has ended back to active';

    public function handle(AuditLog $audit): int
    {
        $ended = 0;

        User::query()
            ->where('status', AccountStatus::Suspended)
            ->where('suspended_until', '<=', now())
            ->select(['id'])
            ->chunkById(500, static function ($users) use ($audit, &$ended): void {
                foreach ($users as $user) {
                    $updated = User::query()->whereKey($user->id)
                        ->where('status', AccountStatus::Suspended)
                        ->where('suspended_until', '<=', now())
                        ->update(['status' => AccountStatus::Active, 'suspended_until' => null]);

                    if ($updated === 1) {
                        $audit->record('account.suspension_ended', null, 'user', $user->id);
                        $ended++;
                    }
                }
            });

        $this->info("Suspensions ended: {$ended}");

        return self::SUCCESS;
    }
}
