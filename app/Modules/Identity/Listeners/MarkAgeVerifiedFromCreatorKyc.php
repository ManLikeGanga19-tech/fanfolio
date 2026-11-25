<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Events\CreatorKycPassed;
use App\Modules\Identity\Events\AgeVerified;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The creator ID check matched an ID and selfie of someone 18 or older: a stronger check
 * than the fan age check, so the account counts as age-verified and is not asked again.
 * Idempotent: a replayed event, or an account already verified, changes nothing.
 */
final readonly class MarkAgeVerifiedFromCreatorKyc
{
    public function __construct(private AuditLog $audit) {}

    public function handle(CreatorKycPassed $event): void
    {
        DB::transaction(function () use ($event): void {
            $user = User::query()->whereKey($event->userId)->lockForUpdate()->first();

            if (! $user instanceof User || $user->age_verified_at !== null) {
                return;
            }

            $user->forceFill(['age_verified_at' => now()])->save();

            $this->audit->record('identity.age_verified_by_creator_kyc', null, 'user', $user->id, [
                'creator' => $event->creatorId,
            ]);

            AgeVerified::dispatch($user->id);
        });
    }
}
